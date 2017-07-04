<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 五  7/31 22:21:23 2015
 *
 * @File Name: command/Sync.php
 * @Description:
 * *****************************************************************/
namespace app\components;

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

class Deploy {
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

    public function start($taskId)
    {
        $this->task = TaskModel::findOne($taskId);
        $this->taskList = [$this->task];
        $taskIdList = [$this->task->id];
        if ($this->task['bind_task_id'])
        {
            $taskIdList = array_merge($taskIdList, explode(',', $this->task['bind_task_id']));
            $this->taskList = TaskModel::find()
                                           ->where(['in', 'id', $taskIdList])
                                           ->all();
        }

        // 清除历史记录
        Record::deleteAll(['in', 'task_id', $taskIdList]);
        // 项目配置
        try {
            foreach ($this->taskList as $task) {
                $this->conf[$task->id] = Project::getConf($task->project_id);
                $this->walleTask[$task->id] = new WalleTask($this->conf[$task->id]);
                $this->walleFolder[$task->id] = new Folder($this->conf[$task->id]);
            }

            if ($this->task->action == TaskModel::ACTION_ONLINE) {
                $this->_makeVersion();
                $this->_initWorkspace();
                $this->_preDeploy();
                $this->_revisionUpdate();
                $this->_postDeploy();
                $this->_transmission();
                $this->_updateRemoteServers('link_id', true);
                $this->_cleanRemoteReleaseVersion();
                $this->_cleanUpLocal('link_id');
            } else {
                $this->_rollback('ex_link_id');
            }

            /** 至此已经发布版本到线上了，需要做一些记录工作 */

            foreach ($this->taskList as $task) {
                // 记录此次上线的版本（软链号）和上线之前的版本
                ///对于回滚的任务不记录线上版本
                if ($task->action == TaskModel::ACTION_ONLINE) {
                    $task->ex_link_id = $this->conf[$task->id]->version;
                }
                // 第一次上线的任务不能回滚、回滚的任务不能再回滚
                if ($task->action == TaskModel::ACTION_ROLLBACK || $task->id == 1) {
                    $task->enable_rollback = TaskModel::ROLLBACK_FALSE;
                }
                $task->status = TaskModel::STATUS_DONE;
                $task->save();

                // 可回滚的版本设置
                $this->_enableRollBack($task);

                // 记录当前线上版本（软链）回滚则是回滚的版本，上线为新版本
                $this->conf[$task->id]->version = $task->link_id;
                $this->conf[$task->id]->save();
            }
        } catch (\Exception $e) {
            foreach ($this->taskList as $task) {
                $task->status = TaskModel::STATUS_FAILED;
                $task->save();
            }
            // 清理本地部署空间
            $this->_cleanUpLocal('link_id');

            throw $e;
        }
    }

    /**
     * 产生一个上线版本
     */
    private function _makeVersion()
    {
        $version = date("Ymd-His", time());
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;
            $task->link_id = $version;
            $ret = $task->save();

            if (!$ret) {
                throw new \Exception(yii::t('walle', 'init deployment workspace error'));
            }
        }

        return true;
    }

    /**
     * 检查目录和权限，工作空间的准备
     * 每一个版本都单独开辟一个工作空间，防止代码污染
     *
     * @return bool
     * @throws \Exception
     */
    private function _initWorkspace()
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;

            $sTime = Command::getMs();
            // 本地宿主机工作区初始化
            $this->walleFolder[$task->id]->initLocalWorkspace($task);
            // 远程目标目录检查，并且生成版本目录
            $ret = $this->walleFolder[$task->id]->initRemoteVersion($task->link_id);
            // 记录执行时间
            $duration = Command::getMs() - $sTime;
            Record::saveRecord($this->walleFolder[$task->id], $task->id, Record::ACTION_PERMSSION, $duration);

            
            if (!$ret) {
                throw new \Exception(yii::t('walle', 'init deployment workspace error'));
            }
        }

        return true;
    }

    /**
     * 更新代码文件
     *
     * @return bool
     * @throws \Exception
     */
    private function _revisionUpdate()
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;

            // 更新代码文件
            $revision = Repo::getRevision($this->conf[$task->id]);
            $sTime = Command::getMs();
            $ret = $revision->updateToVersion($task); // 更新到指定版本
            // 记录执行时间
            $duration = Command::getMs() - $sTime;
            Record::saveRecord($revision, $task->id, Record::ACTION_CLONE, $duration);
            if (!$ret) {
                throw new \Exception(yii::t('walle', 'update code error'));
            }
        }

        return true;
    }

    /**
     * 部署前置触发任务
     * 在部署代码之前的准备工作，如git的一些前置检查、vendor的安装（更新）
     *
     * @return bool
     * @throws \Exception
     */
    private function _preDeploy()
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;

            $sTime = Command::getMs();
            $ret = $this->walleTask[$task->id]->preDeploy($task->link_id);
            // 记录执行时间
            $duration = Command::getMs() - $sTime;
            Record::saveRecord($this->walleTask[$task->id], $task->id, Record::ACTION_PRE_DEPLOY, $duration);

            if (!$ret) {
                throw new \Exception(yii::t('walle', 'pre deploy task error'));
            }
        }
        
        return true;
    }


    /**
     * 部署后置触发任务
     * git代码检出之后，可能做一些调整处理，如vendor拷贝，配置环境适配（mv config-test.php config.php）
     *
     * @return bool
     * @throws \Exception
     */
    private function _postDeploy()
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;

            $sTime = Command::getMs();
            $ret = $this->walleTask[$task->id]->postDeploy($task->link_id);
            // 记录执行时间
            $duration = Command::getMs() - $sTime;
            Record::saveRecord($this->walleTask[$task->id], $task->id, Record::ACTION_POST_DEPLOY, $duration);
            if (!$ret) {
                throw new \Exception(yii::t('walle', 'post deploy task error'));
            }
        }

        return true;
    }

    /**
     * 传输文件/目录到指定目标机器
     *
     * @return bool
     * @throws \Exception
     */
    private function _transmission()
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;

            $sTime = Command::getMs();
            if (Project::getAnsibleStatus()) {
                // ansible copy
                $this->walleFolder[$task->id]->ansibleCopyFiles($this->conf[$task->id], $task);
            } else {
                // 循环 scp
                $this->walleFolder[$task->id]->scpCopyFiles($this->conf[$task->id], $task);
            }

            // 记录执行时间
            $duration = Command::getMs() - $sTime;

            Record::saveRecord($this->walleFolder[$task->id], $task->id, Record::ACTION_SYNC, $duration);
        }

        return true;
    }

    /**
     * 执行远程服务器任务集合
     * 对于目标机器更多的时候是一台机器完成一组命令，而不是每条命令逐台机器执行
     *
     * @param string  $version
     * @param integer $delay 每台机器延迟执行post_release任务间隔, 不推荐使用, 仅当业务无法平滑重启时使用
     * @throws \Exception
     */
    private function _updateRemoteServers($versionField, $isDelay = false)
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;

            $version = $task->{$versionField};
            $delay = $isDelay ? $this->conf[$task->id]->post_release_delay : 0;
            $cmd = [];

            // pre-release task
            if (($preRelease = WalleTask::getRemoteTaskCommand($this->conf[$task->id]->pre_release, $version))) {
                $cmd[] = $preRelease;
            }

            // link
            if (($linkCmd = $this->walleFolder[$task->id]->getLinkCommand($version))) {
                $cmd[] = $linkCmd;
            }

            // post-release task
            if (($postRelease = WalleTask::getRemoteTaskCommand($this->conf[$task->id]->post_release, $version))) {
                $cmd[] = $postRelease;
            }

            $sTime = Command::getMs();
            // run the task package
            $ret = $this->walleTask[$task->id]->runRemoteTaskCommandPackage($cmd, $delay);

            // 记录执行时间
            $duration = Command::getMs() - $sTime;

            Record::saveRecord($this->walleTask[$task->id], $task->id, Record::ACTION_UPDATE_REMOTE, $duration);
            if (!$ret) {
                throw new \Exception(yii::t('walle', 'update servers error'));
            }
        }

        return true;
    }

    /**
     * 可回滚的版本设置
     *
     * @return int
     */
    private function _enableRollBack($task)
    {
        $where = ' status = :status AND project_id = :project_id ';
        $param = [':status' => TaskModel::STATUS_DONE, ':project_id' => $task->project_id];
        $offset = TaskModel::find()
                           ->select(['id'])
                           ->where($where, $param)
                           ->orderBy(['id' => SORT_DESC])
                           ->offset($this->conf[$task->id]->keep_version_num)
                           ->limit(1)
                           ->scalar();
        if (!$offset) {
            return true;
        }

        $where .= ' AND id <= :offset ';
        $param[':offset'] = $offset;

        return TaskModel::updateAll(['enable_rollback' => TaskModel::ROLLBACK_FALSE], $where, $param);
    }

    /**
     * 只保留最大版本数，其余删除过老版本
     */
    private function _cleanRemoteReleaseVersion()
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;
            return $this->walleTask[$task->id]->cleanUpReleasesVersion();
        }
    }

    /**
     * 执行远程服务器任务集合回滚，只操作pre-release、link、post-release任务
     *
     * @param $version
     * @throws \Exception
     */
    public function _rollback($version)
    {
        return $this->_updateRemoteServers($version);
    }

    /**
     * 收尾工作，清除宿主机的临时部署空间
     */
    private function _cleanUpLocal($versionField = null)
    {
        foreach ($this->taskList as $task) {
            Project::$currentProjectId = $task->project_id;

            $version = $task->{$versionField};
            // 创建链接指向
            $this->walleFolder[$task->id]->cleanUpLocal($version);
        }

        return true;
    }
}

