<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 一  9/21 13:48:30 2015
 *
 * @File Name: WalleController.php
 * @Description:
 * *****************************************************************/

namespace app\controllers;

use app\components\Ansible;
use app\components\Command;
use app\components\Controller;
use app\components\Folder;
use app\components\Repo;
use app\components\Task as WalleTask;
use app\models\Project;
use app\models\Group;
use app\models\Record;
use app\models\Task as TaskModel;
use yii;
class WalleController extends Controller
{

    /**
     * 项目配置
     */
    protected $conf;

    /**
     * 上线任务配置
     */
    protected $task;

    /**
     * 绑定上线任务列表
     */
    protected $taskList;

    /**
     * Walle的高级任务
     */
    protected $walleTask;

    /**
     * Ansible 任务
     */
    protected $ansible;

    /**
     * Walle的文件目录操作
     */
    protected $walleFolder;

    public $enableCsrfValidation = false;

    /**
     * 发起上线
     *
     * @throws \Exception
     */
    public function actionStartDeploy()
    {
        $taskId = \Yii::$app->request->post('taskId');
        if (!$taskId) {
            $this->renderJson([], -1, yii::t('walle', 'deployment id is empty'));
        }
        $this->task = TaskModel::findOne($taskId);
        if (!$this->task) {
            throw new \Exception(yii::t('walle', 'deployment id not exists'));
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }
        // 任务失败或者审核通过时可发起上线
        if (!in_array($this->task->status, [TaskModel::STATUS_PASS, TaskModel::STATUS_FAILED])) {
            throw new \Exception(yii::t('walle', 'deployment only done for once'));
        }

        pclose(popen(Yii::$app->basePath.'/yii walle/deploying '.$taskId.' > /dev/null &', 'r'));

        $this->renderJson([]);
    }

    /**
     * 提交任务
     *
     * @return string
     */
    public function actionCheck()
    {
        $projectTable = Project::tableName();
        $groupTable = Group::tableName();
        $projects = Project::find()
                           ->leftJoin(Group::tableName(), "`$groupTable`.`project_id` = `$projectTable`.`id`")
                           ->where([
                               "`$projectTable`.status" => Project::STATUS_VALID,
                               "`$groupTable`.`user_id`" => $this->uid
                           ])
                           ->asArray()
                           ->all();

        return $this->render('check', [
            'projects' => $projects,
        ]);
    }

    /**
     * 项目配置检测，提前发现配置不当之处。
     *
     * @return string
     */
    public function actionDetection($projectId)
    {
        $project = Project::getConf($projectId);
        $log = [];
        $code = 0;

        // 本地git ssh-key是否加入deploy-keys列表
        $revision = Repo::getRevision($project);
        try {

            // 1.检测宿主机检出目录是否可读写
            $codeBaseDir = Project::getDeployFromDir();
            $isWritable = is_dir($codeBaseDir) ? is_writable($codeBaseDir) : @mkdir($codeBaseDir, 0755, true);
            if (!$isWritable) {
                $code = -1;
                $log[] = yii::t('walle', 'hosted server is not writable error', [
                    'user' => getenv("USER"),
                    'path' => $project->deploy_from,
                ]);
            }

            // 2.检测宿主机ssh是否加入git信任
            $ret = $revision->updateRepo();
            if (!$ret) {
                $code = -1;
                $error = $project->repo_type == Project::REPO_GIT ? yii::t('walle', 'ssh-key to git',
                    ['user' => getenv("USER")]) : yii::t('walle', 'correct username passwd');
                $log[] = yii::t('walle', 'hosted server ssh error', [
                    'error' => $error,
                ]);
            }

            if ($project->ansible) {
                $this->ansible = new Ansible($project);

                // 3.检测 ansible 是否安装
                $ret = $this->ansible->test();
                if (!$ret) {
                    $code = -1;
                    $log[] = yii::t('walle', 'hosted server ansible error');
                }
            }
        } catch (\Exception $e) {
            $code = -1;
            $log[] = yii::t('walle', 'hosted server sys error', [
                'error' => $e->getMessage()
            ]);
        }

        // 权限与免密码登录检测
        $this->walleTask = new WalleTask($project);
        try {
            // 4.检测php用户是否加入目标机ssh信任
            $command = 'id';
            $ret = $this->walleTask->runRemoteTaskCommandPackage([$command]);
            if (!$ret) {
                $code = -1;
                $log[] = yii::t('walle', 'target server ssh error', [
                    'local_user' => getenv("USER"),
                    'remote_user' => $project->release_user,
                    'path' => $project->release_to,
                ]);
            }

            if ($project->ansible) {
                // 5.检测 ansible 连接目标机是否正常
                $ret = $this->ansible->ping();
                if (!$ret) {
                    $code = -1;
                    $log[] = yii::t('walle', 'target server ansible ping error');
                }
            }

            // 6.检测php用户是否具有目标机release目录读写权限
            $tmpDir = 'detection' . time();
            $command = sprintf('mkdir -p %s', Project::getReleaseVersionDir($tmpDir));
            $ret = $this->walleTask->runRemoteTaskCommandPackage([$command]);
            if (!$ret) {
                $code = -1;
                $log[] = yii::t('walle', 'target server is not writable error', [
                    'remote_user' => $project->release_user,
                    'path' => $project->release_library,
                ]);
            }

            // 清除
            $command = sprintf('rm -rf %s', Project::getReleaseVersionDir($tmpDir));
            $this->walleTask->runRemoteTaskCommandPackage([$command]);
        } catch (\Exception $e) {
            $code = -1;
            $log[] = yii::t('walle', 'target server sys error', [
                'error' => $e->getMessage()
            ]);
        }

        // 7.路径必须为绝对路径
        $needAbsoluteDir = [
            Yii::t('conf', 'deploy from') => Project::getConf()->deploy_from,
            Yii::t('conf', 'webroot') => Project::getConf()->release_to,
            Yii::t('conf', 'releases') => Project::getConf()->release_library,
        ];
        foreach ($needAbsoluteDir as $tips => $dir) {
            if (0 !== strpos($dir, '/')) {
                $code = -1;
                $log[] = yii::t('walle', 'config dir must absolute', [
                    'path' => sprintf('%s:%s', $tips, $dir),
                ]);
            }
        }

        // task 检测todo...

        if ($code === 0) {
            $log[] = yii::t('walle', 'project configuration works');
        }
        $this->renderJson(join("<br>", $log), $code);
    }

    /**
     * 获取线上文件md5
     *
     * @param $projectId
     */
    public function actionFileMd5($projectId, $file)
    {
        // 配置
        $this->conf = Project::getConf($projectId);

        $this->walleFolder = new Folder($this->conf);
        $projectDir = $this->conf->release_to;
        $file = sprintf("%s/%s", rtrim($projectDir, '/'), $file);

        $this->walleFolder->getFileMd5($file);
        $log = $this->walleFolder->getExeLog();

        $this->renderJson(nl2br($log));
    }

    /**
     * 获取branch分支列表
     *
     * @param $projectId
     */
    public function actionGetBranch($projectId)
    {
        $conf = Project::getConf($projectId);

        $version = Repo::getRevision($conf);
        $list = $version->getBranchList();

        $this->renderJson($list);
    }

    /**
     * 获取commit历史
     *
     * @param $projectId
     */
    public function actionGetCommitHistory($projectId, $branch = 'master')
    {
        $conf = Project::getConf($projectId);
        $revision = Repo::getRevision($conf);

        if ($conf->repo_mode == Project::REPO_MODE_TAG && $conf->repo_type == Project::REPO_GIT) {
            $list = $revision->getTagList();
        } else {
            $list = $revision->getCommitList($branch);
        }
        $this->renderJson($list);
    }

    /**
     * 获取commit之间的文件
     *
     * @param $projectId
     */
    public function actionGetCommitFile($projectId, $start, $end, $branch = 'trunk')
    {
        $conf = Project::getConf($projectId);
        $revision = Repo::getRevision($conf);
        $list = $revision->getFileBetweenCommits($branch, $start, $end);

        $this->renderJson($list);
    }

    /**
     * 上线管理
     *
     * @param $taskId
     * @return string
     * @throws \Exception
     */
    public function actionDeploy($taskId)
    {
        $this->task = TaskModel::find()
                               ->where(['id' => $taskId])
                               ->with(['project'])
                               ->one();
        if (!$this->task) {
            throw new \Exception(yii::t('walle', 'deployment id not exists'));
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception(yii::t('w', 'you are not master of project'));
        }

        $bindTaskList = [];
        if ($this->task['bind_task_id'])
        {
            $bindTaskIdList = explode(',', $this->task['bind_task_id']);
            $bindTaskList = TaskModel::find()
                                           ->where(['in', 'id', $bindTaskIdList])
                                           ->with(['project'])
                                           ->all();
        }

        return $this->render('deploy', [
            'task' => $this->task,
            'bindTaskList' => $bindTaskList,
        ]);
    }

    /**
     * 获取上线进度
     *
     * @param $taskId
     */
    public function actionGetProcess($taskId)
    {
        $record = Record::find()
                        ->select(['percent' => 'action', 'status', 'memo', 'command'])
                        ->where(['task_id' => $taskId,])
                        ->orderBy('id desc')
                        ->asArray()
                        ->one();

        $record['memo'] = stripslashes($record['memo']);
        $record['command'] = stripslashes($record['command']);

        $this->renderJson($record);
    }
    
}
