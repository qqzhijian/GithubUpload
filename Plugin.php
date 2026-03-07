<?php
/**
 * GitHub 附件上传插件
 *
 * @package GithubUpload
 * @author  Xingtu
 * @version 1.1.0
 */

class GithubUpload_Plugin implements Typecho_Plugin_Interface
{
    const UPLOAD_DIR = '/usr/uploads';

    public static function activate()
    {
        if (!extension_loaded('curl')) {
            throw new Typecho_Plugin_Exception(_t('需要 curl 扩展'));
        }
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array(__CLASS__, 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array(__CLASS__, 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array(__CLASS__, 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array(__CLASS__, 'attachmentHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentDataHandle = array(__CLASS__, 'attachmentDataHandle');
        return _t('插件已激活，请设置 GitHub 信息');
    }

    public static function deactivate()
    {
        return _t('插件已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 样式提示
        echo '<style>.notice{background:#FFF6BF;color:#8A6D3B;padding:.5rem;border-left:4px solid #fbbc05;}</style>';
        echo '<p class="notice">请正确填写以下 GitHub 配置信息，否则上传将失败。</p>';

        $github_user = new Typecho_Widget_Helper_Form_Element_Text(
            'githubUser', null, '', _t('GitHub 用户名 *'), _t('您的 GitHub 用户名')
        );
        $github_user->addRule('required', _t('必须填写 GitHub 用户名'));
        $form->addInput($github_user);

        $github_repo = new Typecho_Widget_Helper_Form_Element_Text(
            'githubRepo', null, '', _t('GitHub 仓库名 *'), _t('您的 GitHub 仓库名')
        );
        $github_repo->addRule('required', _t('必须填写仓库名'));
        $form->addInput($github_repo);

        $github_branch = new Typecho_Widget_Helper_Form_Element_Text(
            'githubBranch', null, 'main', _t('GitHub 分支 *'), _t('默认为 main')
        );
        $github_branch->addRule('required', _t('必须填写分支名'));
        $form->addInput($github_branch);

        $github_api_proxy = new Typecho_Widget_Helper_Form_Element_Text(
            'githubApiProxy', null, 'https://ghproxy.com/', _t('GitHub API 代理 *'), _t('例如 https://ghproxy.com/ ，若代理不支持PUT请更换')
        );
        $github_api_proxy->addRule('required', _t('必须填写 API 代理'));
        $form->addInput($github_api_proxy);

        $github_proxy = new Typecho_Widget_Helper_Form_Element_Text(
            'githubProxy', null, 'http://ghproxy.net/', _t('GitHub 加速代理 *'), _t('例如 http://ghproxy.net/')
        );
        $github_proxy->addRule('required', _t('必须填写加速代理'));
        $form->addInput($github_proxy);

        $github_token = new Typecho_Widget_Helper_Form_Element_Text(
            'githubToken', null, '', _t('GitHub Token *'), _t('需 repo 权限的 Personal Access Token')
        );
        $github_token->addRule('required', _t('必须填写 Token'));
        $form->addInput($github_token);

        $github_directory = new Typecho_Widget_Helper_Form_Element_Text(
            'githubDirectory', null, '/usr/uploads', _t('上传目录 *'), _t('例如 /usr/uploads，末尾不加斜杠')
        );
        $github_directory->addRule('required', _t('必须填写上传目录'));
        $form->addInput($github_directory);

        $url_type = new Typecho_Widget_Helper_Form_Element_Select(
            'urlType',
            array('latest' => '访问最新版本', 'direct' => '直接访问'),
            'latest',
            _t('链接访问方式'),
            _t('建议选择“访问最新版本”')
        );
        $form->addInput($url_type);

        $if_save = new Typecho_Widget_Helper_Form_Element_Select(
            'ifSave',
            array('save' => '保存到本地', 'notsave' => '不保存到本地'),
            'save',
            _t('本地备份'),
            _t('保存到本地可保证主题功能正常，GitHub 上传失败时会自动降级为本地文件')
        );
        $form->addInput($if_save);

        $commit_name = new Typecho_Widget_Helper_Form_Element_Text(
            'commitName', null, 'GithubUpload', _t('提交者名称'), _t('Git Commit 作者')
        );
        $form->addInput($commit_name);

        $commit_email = new Typecho_Widget_Helper_Form_Element_Text(
            'commitEmail', null, 'upload@typecho.com', _t('提交者邮箱'), _t('Git Commit 邮箱')
        );
        $form->addInput($commit_email);

        $debug = new Typecho_Widget_Helper_Form_Element_Radio(
            'debug',
            array('0' => '关闭', '1' => '开启'),
            '0',
            _t('调试模式'),
            _t('开启后记录日志到插件目录 debug.log')
        );
        $form->addInput($debug);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    private static function getOptions()
    {
        static $options = null;
        if (null === $options) {
            $options = Helper::options()->plugin('GithubUpload');
        }
        return $options;
    }

    private static function debugLog($msg, $data = null)
    {
        $options = self::getOptions();
        if (empty($options->debug) || $options->debug != '1') return;
        $log_file = dirname(__FILE__) . '/debug.log';
        $log_dir = dirname($log_file);
        if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
        $time = date('Y-m-d H:i:s');
        $log = "[$time] $msg";
        if ($data !== null) {
            $log .= "\n" . print_r($data, true);
        }
        $log .= "\n-----------------------------\n";
        file_put_contents($log_file, $log, FILE_APPEND | LOCK_EX);
    }

    private static function writeErrorLog($path, $content)
    {
        $date = date('[Y/m/d H:i:s]', time());
        $text = $date . ' ' . $path . ' ' . $content . "\n";
        $log_file = dirname(__FILE__) . '/log/error.log';
        $log_dir = dirname($log_file);
        if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
        file_put_contents($log_file, $text, FILE_APPEND | LOCK_EX);
    }

    private static function getSafeName($name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function getUploadDir($relatively = true)
    {
        if ($relatively) {
            return defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR;
        } else {
            return Typecho_Common::url(
                defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR,
                defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__
            );
        }
    }

    private static function getUploadFile($file)
    {
        return isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bytes']) ? $file['bytes'] : (isset($file['bits']) ? $file['bits'] : ''));
    }

    private static function makeUploadDir($path)
    {
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last)) {
            return false;
        }

        $stat = @stat($last);
        $perms = $stat['mode'] & 0007777;
        @chmod($last, $perms);

        return self::makeUploadDir($path);
    }

    /**
     * 构建完整的 GitHub 文件 URL（动态生成，不依赖数据库）
     */
    private static function buildFullUrl($path)
    {
        $options = self::getOptions();
        $proxy = rtrim($options->githubProxy, '/');
        $base = $proxy . '/https://raw.githubusercontent.com/' . $options->githubUser . '/' . $options->githubRepo . '/refs/heads/' . $options->githubBranch;
        $filePath = '/' . ltrim($path, '/');
        return $base . $filePath;
    }

    public static function uploadHandle($file)
    {
        self::debugLog('uploadHandle called', $file);
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);
        if (!Widget_Upload::checkFileType($ext) || Typecho_Common::isAppEngine()) {
            self::debugLog('file type not allowed', $ext);
            return false;
        }

        $options = self::getOptions();
        $date = new Typecho_Date(Typecho_Widget::widget('Widget_Options')->gmtTime);

        $fileDir_relatively = self::getUploadDir(true) . '/' . $date->year . '/' . $date->month;
        $fileDir = self::getUploadDir(false) . '/' . $date->year . '/' . $date->month;
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path_relatively = $fileDir_relatively . '/' . $fileName;
        $path = $fileDir . '/' . $fileName;

        $uploadfile = self::getUploadFile($file);
        if (!isset($uploadfile)) {
            self::debugLog('no upload file tmp');
            return false;
        }
        $fileContent = file_get_contents($uploadfile);
        if ($fileContent === false) {
            self::debugLog('failed to read file content');
            return false;
        }

        /* 上传到 GitHub */
        $data = array(
            'message' => 'Upload file ' . $fileName,
            'content' => base64_encode($fileContent),
        );
        if (!empty($options->commitName) && !empty($options->commitEmail)) {
            $data['committer'] = array(
                'name' => $options->commitName,
                'email' => $options->commitEmail
            );
        }

        $header = array(
            'Content-Type:application/vnd.github.v3.json',
            'User-Agent:' . $options->githubRepo,
            'Authorization: token ' . $options->githubToken
        );

        $apiUrl = $options->githubApiProxy . 'https://api.github.com/repos/' . $options->githubUser . '/' . $options->githubRepo . '/contents' . $path_relatively;
        self::debugLog('GitHub API URL', $apiUrl);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $output = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        self::debugLog('GitHub API response', array('http_code' => $http_code, 'output' => $output, 'curl_error' => $curl_error));

        // 如果 GitHub 上传失败
        if ($http_code != 201 || $curl_error) {
            self::writeErrorLog($path_relatively, '[GitHub][upload][' . $http_code . '] ' . $curl_error);
            // 如果开启了本地保存，则降级为本地文件
            if ($options->ifSave == 'save') {
                // 保存到本地
                if (!is_dir($fileDir)) {
                    if (self::makeUploadDir($fileDir)) {
                        file_put_contents($path, $fileContent);
                    } else {
                        self::writeErrorLog($path_relatively, '[local]Directory creation failed');
                        return false;
                    }
                } else {
                    file_put_contents($path, $fileContent);
                }
                // 返回本地附件信息
                $localUrl = Typecho_Common::url($date->year . '/' . $date->month . '/' . $fileName, Helper::options()->siteUrl . 'usr/uploads/');
                return array(
                    'name' => $file['name'],
                    'path' => $path_relatively,
                    'url'  => $localUrl,
                    'size' => $file['size'],
                    'type' => $ext,
                    'mime' => @Typecho_Common::mimeContentType($path)
                );
            }
            return false; // 未开启本地保存则返回失败
        }

        // 如果需要本地保存，同时保存到本地
        if ($options->ifSave == 'save') {
            if (!is_dir($fileDir)) {
                if (self::makeUploadDir($fileDir)) {
                    file_put_contents($path, $fileContent);
                } else {
                    self::writeErrorLog($path_relatively, '[local]Directory creation failed');
                }
            } else {
                file_put_contents($path, $fileContent);
            }
        }

        $result = array(
            'name' => $file['name'],
            'path' => $path_relatively,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @Typecho_Common::mimeContentType($path)
        );
        self::debugLog('uploadHandle result', $result);
        return $result;
    }

    public static function modifyHandle($content, $file)
    {
        self::debugLog('modifyHandle called', array('content' => $content, 'file' => $file));
        return false; // 简化处理，可自行实现
    }

    public static function deleteHandle(array $content)
    {
        self::debugLog('deleteHandle called', $content);
        $options = self::getOptions();
        $path = $content['attachment']->path;
        $github_path = $options->githubDirectory . str_replace(self::getUploadDir(), '', $path);
        $branch = $options->githubBranch;

        // 获取文件 sha
        $header = array('User-Agent:' . $options->githubRepo);
        $getUrl = $options->githubApiProxy . 'https://api.github.com/repos/' . $options->githubUser . '/' . $options->githubRepo . '/contents' . $github_path . '?ref=' . $branch;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $getUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $output = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $data = json_decode($output, true);
            $sha = $data['sha'] ?? '';
            if ($sha) {
                $delData = array(
                    'message' => 'Delete file',
                    'sha' => $sha,
                    'branch' => $branch
                );
                $delUrl = $options->githubApiProxy . 'https://api.github.com/repos/' . $options->githubUser . '/' . $options->githubRepo . '/contents' . $github_path;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $delUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($header, array('Content-Type:application/json', 'Authorization: token ' . $options->githubToken)));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($delData));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        // 删除本地文件
        if ($options->ifSave == 'save') {
            $localFile = __TYPECHO_ROOT_DIR__ . $path;
            if (file_exists($localFile)) {
                unlink($localFile);
            }
        }
        return true;
    }

    /**
     * 获取实际文件访问URL（核心修复：直接使用 $content->path）
     */
    public static function attachmentHandle($content)
    {
        self::debugLog('attachmentHandle called', $content);

        // 获取附件路径（兼容对象和数组）
        if (is_object($content) && isset($content->path)) {
            $path = $content->path;
        } elseif (is_array($content) && isset($content['path'])) {
            $path = $content['path'];
        } else {
            self::debugLog('attachmentHandle: unable to get path');
            // 返回基础URL作为降级
            $options = self::getOptions();
            $proxy = rtrim($options->githubProxy, '/');
            return $proxy . '/https://raw.githubusercontent.com/' . $options->githubUser . '/' . $options->githubRepo . '/refs/heads/' . $options->githubBranch . '/';
        }

        self::debugLog('attachmentHandle extracted path', $path);
        $url = self::buildFullUrl($path);
        self::debugLog('attachmentHandle built url', $url);
        return $url;
    }

    public static function attachmentDataHandle($content)
    {
        self::debugLog('attachmentDataHandle called', $content);
        $url = self::attachmentHandle($content);
        return file_get_contents($url);
    }
}