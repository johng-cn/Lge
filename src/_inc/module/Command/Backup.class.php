<?php
/**
 * Lge命令：根据备份配置文件备份MySQL及文件数据。
 *
 * @author john
 */

namespace Lge;

if (!defined('LGE')) {
    exit('Include Permission Denied!');
}

/**
 * Class Module_Command_Backup
 *
 * @package Lge
 */
class Module_Command_Backup extends BaseModule
{

    /**
     * 获得实例.
     *
     * @return Module_Command_Backup
     */
    public static function instance()
    {
        return self::_instanceInternal(__CLASS__);
    }

    /**
     * 默认入口函数.
     *
     * @return void
     */
    public function run()
    {
        $id = shell_exec("id -u");
        $id = trim($id);
        if ($id != "0") {
            Lib_Console::perror("This script must be running as root\n");
            exit(1);
        }

        $configFilePath = Lib_ConsoleOption::instance()->getOption('config');
        if (empty($configFilePath)) {
            Lib_Console::perror("Please specify a backup config file!\n");
            exit(1);
        }
        if (!file_exists($configFilePath)) {
            Lib_Console::perror("Specified backup config file does not exist!\n");
            exit(1);
        }

        Logger::setAdapterFileLogPath("/var/log/lge/backup/");

        Logger::log('==================start===================');
        Logger::log("using backup config file: {$configFilePath}");

        $config       = include $configFilePath;
        $centerConfig = $config['backup_center'];
        $groupsConfig = $config['backup_groups'];
        $backupDir    = rtrim($centerConfig['folder'], '/');
        foreach ($groupsConfig as $groupName => $backupConfig) {
            // 首先备份数据库
            if (!empty($backupConfig['data'])) {
                foreach ($backupConfig['data'] as $dataConfig) {
                    $ssh = new Lib_Network_Ssh($dataConfig['host'], $dataConfig['port'], $dataConfig['user'], $dataConfig['pass']);
                    foreach ($dataConfig['databases'] as $db) {
                        // 备份中心目录
                        $centerBackupDir = "{$backupDir}/{$groupName}/data/{$dataConfig['host']}/{$db['host']}";
                        if (!file_exists($centerBackupDir)) {
                            @mkdir($centerBackupDir, 0777, true);
                        }
                        // 远程创建临时目录
                        $dataBackupDir = "/tmp/lge_backuper/data";
                        $ssh->syncCmd("mkdir -p {$dataBackupDir}");
                        // 远程执行执行备份
                        foreach ($db['names'] as $name => $keepDays) {
                            if ($keepDays > 0) {
                                Logger::log("data backing up, server:{$dataConfig['host']}, host:{$db['port']}, db:{$name}");
                                $date     = date('Ymd');
                                $fileName = "{$name}.{$date}.sql.bz2";
                                $filePath = "{$dataBackupDir}/{$fileName}";
                                $shellCmd = "mysqldump -C -h{$db['host']} -P{$db['port']} -u{$db['user']} -p{$db['pass']} {$name} | bzip2 > {$filePath}";
                                $ssh->syncCmd($shellCmd);
                                // 将远程备份文件同步到备份中心
                                $centerBackupFilePath = "{$centerBackupDir}/{$fileName}";
                                $ssh->getFile($filePath, $centerBackupFilePath);
                                // 备份完成后清除远程的备份文件
                                $ssh->syncCmd("rm {$filePath}");
                            }
                            // 本地的备份文件数量控制
                            $this->_clearDirByKeepDays($centerBackupDir, $keepDays);
                        }
                    }
                }
            }

            // 其次增量备份项目文件
            if (!empty($backupConfig['file'])) {
                foreach ($backupConfig['file'] as $fileConfig) {
                    $fileBackupDir = "{$backupDir}/{$groupName}/file/{$fileConfig['host']}";
                    if (!file_exists($fileBackupDir)) {
                        @mkdir($fileBackupDir, 0777, true);
                    }
                    foreach ($fileConfig['folders'] as $folderPath => $keepDays) {
                        Logger::log("file backing up, server:{$fileConfig['host']}, host:{$fileConfig['port']}, folder:{$folderPath}");
                        $host = $centerConfig['host'];
                        $port = $centerConfig['port'];
                        $user = $centerConfig['user'];
                        $pass = $centerConfig['pass'];
                        $folderPath = rtrim($folderPath, '/');
                        $folderName = basename($folderPath);
                        try {
                            // 由于采用的是rsync增加备份机制，因此备份的文件夹中会保留一份不做压缩的目录，作为备份的文件之一，所以计数时需要做处理
                            $backupDirPath = rtrim($fileBackupDir, '/').'/'.$folderName;
                            if (file_exists($backupDirPath)) {
                                if ($keepDays < 1) {
                                    shell_exec("rm -fr {$backupDirPath}");
                                    $this->_clearDirByKeepDays($fileBackupDir, 0, $folderName);
                                    continue;
                                } elseif ($keepDays == 1) {
                                    $this->_clearDirByKeepDays($fileBackupDir, 0, $folderName);
                                }
                            }

                            $ssh = new Lib_Network_Ssh($fileConfig['host'], $fileConfig['port'], $fileConfig['user'], $fileConfig['pass']);
                            // 先判断有没有安装sshpass工具，没有则自动安装
                            $result = $ssh->checkCmd('sshpass');
                            if (empty($result)) {
                                if (!empty($ssh->checkCmd('apt-get'))) {
                                    // debian 系统
                                    $ssh->syncCmd("echo \"{$fileConfig['pass']}\" | sudo -S apt-get install -y sshpass");
                                } elseif (!empty($ssh->checkCmd('yum'))) {
                                    // rhel 系统，注意这个时候只有root用户才能执行该命令
                                    $ssh->syncCmd("yum install -y sshpass");
                                }
                                if (!$ssh->checkCmd('sshpass')) {
                                    Logger::log("sshpass not installed, break");
                                }
                            }
                            $ssh->syncCmd("rsync -aurvz --delete -e 'sshpass -p {$pass} ssh -p {$port}' {$folderPath} {$user}@{$host}:{$fileBackupDir}");
                            // 执行目录压缩
                            if ($keepDays > 1) {
                                $this->_compressBackupFileDir($backupDirPath, date('Ymd'));
                                $this->_clearDirByKeepDays($fileBackupDir, $keepDays - 1, $folderName);
                            }
                        } catch (\Exception $e) {
                            Logger::log($e->getMessage());
                        }
                    }
                }
            }
            Lib_Console::psuccess("Done!\n\n");
        }

        Logger::log('===================end====================');
    }

    /**
     * 压缩备份的文件目录
     *
     * @param string $backupFileDirPath 文件目录绝对路径
     * @param string $date              备份文件的日期(例如:20170606)
     *
     * @return void
     */
    private function _compressBackupFileDir($backupFileDirPath, $date)
    {
        if (file_exists($backupFileDirPath)) {
            $currentDirPath = getcwd();
            $dirPath = dirname($backupFileDirPath);
            $dirName = basename($backupFileDirPath);
            chdir($dirPath);
            exec("tar -cjvf {$dirPath}/{$dirName}.{$date}.tar.bz2 {$dirName}");
            chdir($currentDirPath);
        }
    }

    /**
     * 按照给定天数清除超过保存期限的备份文件。
     *
     * @param string  $dirPath        备份目录绝对路径
     * @param integer $keepDays       保存天数
     * @param string  $fileNamePrefix 文件名前缀
     *
     * @return void
     */
    private function _clearDirByKeepDays($dirPath, $keepDays, $fileNamePrefix = null)
    {
        $files = array_diff(scandir($dirPath), array('..', '.'));
        // 只计算压缩文件的数量
        foreach ($files as $k => $file) {
            if (!empty($fileNamePrefix)) {
                if (!preg_match("/^{$fileNamePrefix}.+\.bz2/", $file)) {
                    unset($files[$k]);
                }
            } elseif (!preg_match('/.+\.bz2/', $file)) {
                unset($files[$k]);
            }
        }
        while (count($files) > $keepDays) {
            $file     = array_shift($files);
            $filePath = $dirPath.'/'.$file;
            Logger::log("Clearing expired file: {$filePath}");
            unlink($filePath);
        }
    }

}
