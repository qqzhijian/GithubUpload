<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * GithubUpload 插件
 * 核心功能：
 * 1. GitHub 附件上传（保留原上传逻辑）
 * 2. 智能图片URL替换（安全加固版）
 * 3. 自动判定外链链接，不修改已有完整URL
 * 
 * @package GithubUpload
 * @author  xingtu
 * @version 1.1.0
 */
class GithubUpload_Plugin implements Typecho_Plugin_Interface
{
    // ========== 基础常量定义 ==========
    const UPLOAD_DIR = '/usr/uploads';
    const SAFE_ALLOWED_PATH = '/usr/uploads/';
    const DEBUG_CSS_SAFE = 'margin:1rem;padding:1rem;background:#f8f9fa;border:1px solid #e9ecef;border-radius:4px;font-size:13px;line-height:1.4;';
    
    // ========== 插件激活/禁用 ==========
    public static function activate()
    {
        // 注册GitHub上传相关钩子
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array(__CLASS__, 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array(__CLASS__, 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array(__CLASS__, 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array(__CLASS__, 'attachmentHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentDataHandle = array(__CLASS__, 'attachmentDataHandle');
        
        // 注册URL替换相关钩子
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = [__CLASS__, 'parseContentSafe'];
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = [__CLASS__, 'parseContentSafe'];
        
        return _t('GithubUpload 插件已激活<br>1. 请配置GitHub上传信息<br>2. URL替换功能默认启用安全校验，不影响现有外链');
    }

    public static function deactivate()
    {
        return _t('GithubUpload 插件已禁用');
    }

    // ========== 插件配置面板 ==========
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 样式提示
        echo '<style>
            .notice{background:#FFF6BF;color:#8A6D3B;padding:.5rem;border-left:4px solid #fbbc05;margin:1rem 0;}
            .section-title{margin:2rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid #eee;font-weight:bold;}
            .warn-text{color:#dc3545;font-size:.9rem;}
        </style>';
        
        // ========== 第一部分：GitHub上传配置 ==========
        echo '<h3 class="section-title">📤 GitHub 附件上传配置</h3>';
        echo '<p class="notice">请正确填写以下 GitHub 配置信息，否则上传将失败。</p>';
        
        // GitHub基础配置
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
            'githubApiProxy', null, 'https://api.github.com/', _t('GitHub API 地址 *'), 
            _t('直接使用 https://api.github.com/ 或通过代理如 https://ghproxy.com/https://api.github.com/')
        );
        $github_api_proxy->addRule('required', _t('必须填写 API 地址'));
        $form->addInput($github_api_proxy);
        
        $github_proxy = new Typecho_Widget_Helper_Form_Element_Text(
            'githubProxy', null, 'https://raw.githubusercontent.com/', _t('GitHub 文件访问地址 *'), 
            _t('直接使用 https://raw.githubusercontent.com/ 或通过代理如 https://ghproxy.com/https://raw.githubusercontent.com/')
        );
        $github_proxy->addRule('required', _t('必须填写文件访问地址'));
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
            _t('建议选择"访问最新版本"')
        );
        $form->addInput($url_type);
        
        $if_save = new Typecho_Widget_Helper_Form_Element_Select(
            'ifSave',
            array('save' => '保存到本地', 'notsave' => '不保存到本地'),
            'save',
            _t('本地备份'),
            _t('<span class="warn-text">注意：</span>保存到本地可保证主题功能正常，GitHub 上传失败时会自动降级为本地文件')
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
        
        $github_debug = new Typecho_Widget_Helper_Form_Element_Radio(
            'githubDebug',
            array('0' => '关闭', '1' => '开启'),
            '0',
            _t('GitHub上传调试模式'),
            _t('<span class="warn-text">注意：</span>开启后记录日志到插件目录 debug.log，生产环境请关闭')
        );
        $form->addInput($github_debug);
        
        // ========== 第二部分：智能URL替换配置 ==========
        echo '<h3 class="section-title">🔗 智能图片URL替换配置</h3>';
        echo '<p class="notice"><span class="warn-text">核心特性：</span>仅替换 /usr/uploads/ 纯相对路径，自动忽略已有完整外链（http/https开头）</p>';
        
        $proxyPrefix = new Typecho_Widget_Helper_Form_Element_Text(
            'proxy_url',
            NULL,
            'http://ghproxy.net/https://raw.githubusercontent.com/qqzhijian/dianr_cn/refs/heads/master',
            _t('外链代理前缀 *'),
            _t('<span class="warn-text">注意：</span>仅支持 http/https 协议地址，系统将自动过滤恶意字符与非法路径')
        );
        $proxyPrefix->input->setAttribute('style', 'width:100%;');
        $proxyPrefix->addRule('required', _t('必须填写外链代理前缀'));
        $form->addInput($proxyPrefix);

        $runMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'run_mode',
            [
                'proxy'  => _t('外链代理模式（推荐）'),
                'local'  => _t('本地原始模式（不替换）')
            ],
            'proxy',
            _t('运行模式'),
            _t('<span class="warn-text">注意：</span>本地模式不做任何URL替换，仅用于安全测试')
        );
        $form->addInput($runMode);

        $url_debug = new Typecho_Widget_Helper_Form_Element_Radio(
            'urlDebug',
            [
                '0' => _t('关闭（生产推荐）'),
                '1' => _t('开启（仅测试）')
            ],
            '0',
            _t('URL替换调试模式'),
            _t('<span class="warn-text">注意：</span>开启后仅管理员可见调试日志，生产环境请关闭')
        );
        $form->addInput($url_debug);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    // ========== 通用方法：获取插件配置 ==========
    private static function getOptions()
    {
        static $options = null;
        if (null === $options) {
            $options = Helper::options()->plugin('GithubUpload'); // 关键修改：插件名称对应
        }
        return $options;
    }

    // ========== GitHub上传相关方法 ==========
    private static function debugLog($msg, $data = null)
    {
        $options = self::getOptions();
        if (empty($options->githubDebug) || $options->githubDebug != '1') return;
        
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
        $path = preg_replace("/\\+/", '/', $path);
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
    
    private static function buildFullUrl($path)
    {
        $options = self::getOptions();
        $proxy = rtrim($options->githubProxy, '/');
        
        if (strpos($proxy, 'raw.githubusercontent.com') !== false) {
            $filePath = '/' . ltrim($path, '/');
            return $proxy . $filePath;
        }
        
        $base = $proxy . '/https://raw.githubusercontent.com/' . $options->githubUser . '/' . $options->githubRepo . '/refs/heads/' . $options->githubBranch;
        $filePath = '/' . ltrim($path, '/');
        return $base . $filePath;
    }
    
    private static function curlRequest($url, $method = 'GET', $headers = array(), $data = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $output = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        return array(
            'output' => $output,
            'http_code' => $http_code,
            'error' => $curl_error
        );
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
            'Content-Type: application/vnd.github.v3+json',
            'User-Agent: ' . $options->githubRepo,
            'Authorization: token ' . $options->githubToken
        );
        
        $apiBase = rtrim($options->githubApiProxy, '/');
        $repoPath = '/repos/' . $options->githubUser . '/' . $options->githubRepo . '/contents' . $path_relatively;
        
        if (strpos($apiBase, 'api.github.com') === false) {
            $apiUrl = $apiBase . '/https://api.github.com' . $repoPath;
        } else {
            $apiUrl = $apiBase . $repoPath;
        }
        
        self::debugLog('GitHub API URL', $apiUrl);
        
        $result = self::curlRequest($apiUrl, 'PUT', $header, $data);
        
        self::debugLog('GitHub API response', array(
            'http_code' => $result['http_code'],
            'output' => $result['output'],
            'curl_error' => $result['error']
        ));
        
        if (!in_array($result['http_code'], array(200, 201)) || $result['error']) {
            self::writeErrorLog($path_relatively, '[GitHub][upload][' . $result['http_code'] . '] ' . $result['error']);
            
            if ($options->ifSave == 'save') {
                if (!is_dir($fileDir)) {
                    if (!self::makeUploadDir($fileDir)) {
                        self::writeErrorLog($path_relatively, '[local]Directory creation failed');
                        return false;
                    }
                }
                file_put_contents($path, $fileContent);
                
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
            return false;
        }
        
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
        
        $result_data = array(
            'name' => $file['name'],
            'path' => $path_relatively,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @Typecho_Common::mimeContentType($path)
        );
        
        self::debugLog('uploadHandle result', $result_data);
        return $result_data;
    }
    
    public static function modifyHandle($content, $file)
    {
        self::debugLog('modifyHandle called', array('content' => $content, 'file' => $file));
        return false;
    }
    
    public static function deleteHandle(array $content)
    {
        self::debugLog('deleteHandle called', $content);
        
        $options = self::getOptions();
        $path = $content['attachment']->path;
        $github_path = $options->githubDirectory . str_replace(self::getUploadDir(), '', $path);
        $branch = $options->githubBranch;
        
        $header = array(
            'User-Agent: ' . $options->githubRepo,
            'Authorization: token ' . $options->githubToken
        );
        
        $apiBase = rtrim($options->githubApiProxy, '/');
        $repoPath = '/repos/' . $options->githubUser . '/' . $options->githubRepo . '/contents' . $github_path;
        
        if (strpos($apiBase, 'api.github.com') === false) {
            $getUrl = $apiBase . '/https://api.github.com' . $repoPath . '?ref=' . $branch;
        } else {
            $getUrl = $apiBase . $repoPath . '?ref=' . $branch;
        }
        
        $result = self::curlRequest($getUrl, 'GET', $header);
        
        if ($result['http_code'] == 200) {
            $data = json_decode($result['output'], true);
            $sha = $data['sha'] ?? '';
            
            if ($sha) {
                $delData = array(
                    'message' => 'Delete file',
                    'sha' => $sha,
                    'branch' => $branch
                );
                
                if (strpos($apiBase, 'api.github.com') === false) {
                    $delUrl = $apiBase . '/https://api.github.com' . $repoPath;
                } else {
                    $delUrl = $apiBase . $repoPath;
                }
                
                $delHeaders = array(
                    'Content-Type: application/json',
                    'User-Agent: ' . $options->githubRepo,
                    'Authorization: token ' . $options->githubToken
                );
                
                self::curlRequest($delUrl, 'DELETE', $delHeaders, $delData);
            }
        }
        
        if ($options->ifSave == 'save') {
            $localFile = __TYPECHO_ROOT_DIR__ . $path;
            if (file_exists($localFile)) {
                unlink($localFile);
            }
        }
        
        return true;
    }
    
    public static function attachmentHandle($content)
    {
        self::debugLog('attachmentHandle called', $content);
        
        if (is_object($content) && isset($content->path)) {
            $path = $content->path;
        } elseif (is_array($content) && isset($content['path'])) {
            $path = $content['path'];
        } else {
            self::debugLog('attachmentHandle: unable to get path');
            $options = self::getOptions();
            return self::buildFullUrl('/');
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

    // ========== 智能URL替换相关方法 ==========
    private static function getSafePluginOptions()
    {
        $option = self::getOptions();

        return [
            'proxy_url' => self::filterProxyUrl($option->proxy_url ?? ''),
            'run_mode'  => in_array($option->run_mode ?? '', ['proxy','local']) ? ($option->run_mode) : 'proxy',
            'urlDebug'     => $option->urlDebug === '1' ? '1' : '0'
        ];
    }

    private static function filterProxyUrl($url)
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }
        return preg_replace('#[\x00-\x1F\x7F<>"\']#', '', $url);
    }

    private static function filterSafePath($path)
    {
        $path = str_replace(['../', './', '%00', "\0"], '', $path);
        return preg_replace('#[^a-z0-9\/_\-.]#i', '', $path);
    }

    private static function isSafeUrl($url)
    {
        if (empty($url)) return false;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function outputDebug($content, $opt, $log)
    {
        if ($opt['urlDebug'] !== '1') return $content;

        if (!Typecho_Widget::widget('Widget_User')->hasLogin()) {
            return $content;
        }

        $html = '<div style="' . htmlspecialchars(self::DEBUG_CSS_SAFE, ENT_QUOTES) . '">';
        $html .= '<strong>📝 imgURL 安全调试面板</strong><br>';
        $html .= '运行模式：' . htmlspecialchars($opt['run_mode'], ENT_QUOTES) . '<br>';
        $html .= '替换条数：' . count($log) . '<br>';

        foreach ($log as $item) {
            $html .= '原相对路径：' . htmlspecialchars($item['orig'], ENT_QUOTES) . '<br>';
            $html .= '替换后外链：' . $item['safe'] . '<br>';
        }
        $html .= '</div>';

        return $content . $html;
    }

    private static function logError($msg)
    {
        $msg = mb_substr(strip_tags($msg), 0, 200);
        $logDir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        
        file_put_contents(
            $logDir . 'github_imgurl_safe_error.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public static function parseContentSafe($content, $widget, $lastResult)
    {
        $content = $lastResult ?: $content;

        if (trim($content) === '') return $content;

        try {
            $opt = self::getSafePluginOptions();

            if ($opt['run_mode'] === 'local') {
                return self::outputDebug($content, $opt, []);
            }

            // 核心正则：仅匹配 非http/https开头 的 /usr/uploads/ 相对路径
            // 自动忽略已有外链，不影响现有链接
            $pattern = '#(src|href)=([\'"])(?!http://|https://|//)(/usr/uploads/[^"\'>\s]+)\2#i';

            $replaceLog = [];
            $content = preg_replace_callback($pattern, function($m) use ($opt, &$replaceLog) {
                $attr   = $m[1];
                $quote  = $m[2];
                $path   = $m[3];

                // 路径白名单校验
                if (strpos($path, self::SAFE_ALLOWED_PATH) !== 0) {
                    return $attr . '=' . $quote . $path . $quote;
                }

                // 路径安全过滤
                $path = self::filterSafePath($path);

                // 拼接外链（自动处理末尾斜杠）
                $targetUrl = rtrim($opt['proxy_url'], '/') . $path;

                // URL合法性校验
                if (!self::isSafeUrl($targetUrl)) {
                    return $attr . '=' . $quote . $path . $quote;
                }

                $replaceLog[] = [
                    'orig' => $path,
                    'safe' => htmlspecialchars($targetUrl, ENT_QUOTES)
                ];

                // 安全输出，防止XSS
                return $attr . '=' . $quote . htmlspecialchars($targetUrl, ENT_QUOTES) . $quote;

            }, $content);

            $content = self::outputDebug($content, $opt, $replaceLog);

        } catch (Exception $e) {
            self::logError($e->getMessage());
        }

        return $content;
    }
}
