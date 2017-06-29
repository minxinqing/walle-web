<?php
/**
 * @var yii\web\View $this
 */
$this->title = yii::t('walle', 'deploying');
use \app\models\Task;
use yii\helpers\Url;
?>
<style>
    .status > span {
        float: left;
        font-size: 12px;
        width: 14%;
        text-align: right;
    }
    .btn-deploy {
        margin-left: 30px;
    }
    .btn-return {
        /*float: right;*/
        margin-left: 30px;
    }
</style>
<div class="box task-box-<?= $task->id ?>" style="height: 100%">
    <h4 class="box-title header smaller red">
            <i class="icon-map-marker"></i><?= \Yii::t('w', 'conf_level_' . $task->project['level']) ?>
            -
            <?= $task->project->name ?>
            ：
            <?= $task->title ?>
            （<?= $task->project->repo_mode . ':' . $task->branch ?> <?= yii::t('walle', 'version') ?><?= $task->commit_id ?>）
            <?php if (in_array($task->status, [Task::STATUS_PASS, Task::STATUS_FAILED])) { ?>
                <?php 
                    $showProcessIds = [$task->id];
                    if ($task->bind_task_id)
                    {
                        $showProcessIds = array_merge($showProcessIds, explode(',', $task->bind_task_id));
                    }
                ?>
                <button type="submit" class="btn btn-primary btn-deploy" data-id="<?= $task->id ?>" data-show-process-ids=<?= json_encode($showProcessIds) ?>><?= yii::t('walle', 'deploy') ?></button>
            <?php } ?>
            <a class="btn btn-success btn-return" href="<?= Url::to('@web/task/index') ?>"><?= yii::t('walle', 'return') ?></a>
    </h4>
    <div class="status">
        <span><i class="fa fa-circle-o text-yellow step-1"></i><?= yii::t('walle', 'process_detect') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-2"></i><?= yii::t('walle', 'process_pre-deploy') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-3"></i><?= yii::t('walle', 'process_checkout') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-4"></i><?= yii::t('walle', 'process_post-deploy') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-5"></i><?= yii::t('walle', 'process_rsync') ?></span>
        <span style="width: 28%"><i class="fa fa-circle-o text-yellow step-6"></i><?= yii::t('walle', 'process_update') ?></span>
    </div>
    <div style="clear:both"></div>
    <div class="progress progress-small progress-striped active">
        <div class="progress-bar progress-status progress-bar-success" style="width: <?= $task->status == Task::STATUS_DONE ? 100 : 0 ?>%;"></div>
    </div>

    <div class="alert alert-block alert-success result-success" style="<?= $task->status != Task::STATUS_DONE ? 'display: none' : '' ?>">
        <h4><i class="icon-thumbs-up"></i><?= yii::t('walle', 'done') ?></h4>
        <p><?= yii::t('walle', 'done praise') ?></p>

    </div>

    <div class="alert alert-block alert-danger result-failed" style="display: none">
        <h4><i class="icon-bell-alt"></i><?= yii::t('walle', 'error title') ?></h4>
        <span class="error-msg">
        </span>
        <br><br>
        <i class="icon-bullhorn"></i><span><?= yii::t('walle', 'error todo') ?></span>
    </div>

</div>

<?php foreach($bindTaskList as $bindTask) { ?>
<div class="box task-box-<?= $bindTask->id ?>" style="height: 100%" >
    <h4 class="box-title header smaller red">
            <i class="icon-map-marker"></i><?= \Yii::t('w', 'conf_level_' . $bindTask->project['level']) ?>
            -
            <?= $bindTask->project->name ?>
            ：
            <?= $bindTask->title ?>
            （<?= $bindTask->project->repo_mode . ':' . $bindTask->branch ?> <?= yii::t('walle', 'version') ?><?= $bindTask->commit_id ?>）
    </h4>
    <div class="status">
        <span><i class="fa fa-circle-o text-yellow step-1"></i><?= yii::t('walle', 'process_detect') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-2"></i><?= yii::t('walle', 'process_pre-deploy') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-3"></i><?= yii::t('walle', 'process_checkout') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-4"></i><?= yii::t('walle', 'process_post-deploy') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-5"></i><?= yii::t('walle', 'process_rsync') ?></span>
        <span style="width: 28%"><i class="fa fa-circle-o text-yellow step-6"></i><?= yii::t('walle', 'process_update') ?></span>
    </div>
    <div style="clear:both"></div>
    <div class="progress progress-small progress-striped active">
        <div class="progress-bar progress-status progress-bar-success" style="width: <?= $bindTask->status == Task::STATUS_DONE ? 100 : 0 ?>%;"></div>
    </div>

</div>
<?php }?>

<script type="text/javascript">
    $(function() {
        $('.btn-deploy').click(function() {
            $this = $(this);
            $this.addClass('disabled');
            var task_id = $(this).data('id');
            var process_task_ids = $(this).data("show-process-ids");
            var action = '';
            var detail = '';
            var timer;
            $.post("<?= Url::to('@web/walle/start-deploy') ?>", {taskId: task_id}, function(o) {
                action = o.code ? o.msg + ':' : '';
                if (o.code != 0) {
                    clearInterval(timer);
                    $('.progress-status').removeClass('progress-bar-success').addClass('progress-bar-danger');
                    $('.error-msg').text(action + detail);
                    $('.result-failed').show();
                    $this.removeClass('disabled');
                }
            });
            $('.progress-status').attr('aria-valuenow', 10).width('10%');
            $('.result-failed').hide();

            function getProcess() {
                if (check_process_task_ids.length == 0)
                {
                    clearInterval(timer);
                }

                check_process_task_ids.forEach(function(process_task_id){
                    $.get("<?= Url::to('@web/walle/get-process?taskId=') ?>" + process_task_id, function (o) {
                        data = o.data;
                        // 执行失败
                        if (0 == data.status) {
                            clearInterval(timer);
                            $(".task-box-" + process_task_id + ' .step-' + data.step).removeClass('text-yellow').addClass('text-red');
                            $(".task-box-" + process_task_id + ' .progress-status').removeClass('progress-bar-success').addClass('progress-bar-danger');
                            detail = o.msg + ':' + data.memo + '<br>' + data.command;
                            $('.error-msg').html(action + detail);
                            $('.result-failed').show();
                            $this.removeClass('disabled');
                            return;
                        } else {
                            $(".task-box-" + process_task_id + ' .progress-status')
                                .removeClass('progress-bar-danger progress-bar-striped')
                                .addClass('progress-bar-success')
                        }
                        if (0 != data.percent) {
                            $(".task-box-" + process_task_id + ' .progress-status').attr('aria-valuenow', data.percent).width(data.percent + '%');
                        }
                        if (100 == data.percent) {
                            $(".task-box-" + process_task_id + ' .progress-status').removeClass('progress-bar-striped').addClass('progress-bar-success');
                            $(".task-box-" + process_task_id + ' .progress-status').parent().removeClass('progress-striped');
                            $(".task-box-" + process_task_id + ' .result-success').show();

                            delete check_process_task_ids[check_process_task_ids.indexOf(process_task_id)];
                        }
                        for (var i = 1; i <= data.step; i++) {
                            $(".task-box-" + process_task_id + ' .step-' + i).removeClass('text-yellow text-red')
                                .addClass('text-green progress-bar-striped')
                        }
                    });
                });
            }
            var check_process_task_ids = process_task_ids;
            timer = setInterval(getProcess, 1500);
        })

        var _hmt = _hmt || [];
        (function() {
            var hm = document.createElement("script");
            hm.src = "//hm.baidu.com/hm.js?5fc7354aff3dd67a6435818b8ef02b52";
            var s = document.getElementsByTagName("script")[0];
            s.parentNode.insertBefore(hm, s);
        })();
    })

</script>
