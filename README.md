# GithubUpload Typecho插件
修复UploadGithubFor Typecho插件-Github图床，基于https://github.com/smallfawn/UploadGithubForTypecho 改版，小改布局和增加调试模式，供AI提供修复方法

一键将附件上传到GitHub，支持国内加速。

## 🚀 核心功能

- **一键上传**：文章插图、附件自动传至GitHub
- **国内加速**：支持FastGit、GHProxy等加速服务
- **智能目录**：自动创建 `年/月/日` 目录结构
- **安全命名**：自动生成唯一文件名，避免乱码
- **本地备份**：可选同时保存到本地服务器

## 📦 安装

1. 下载插件，文件夹重命名为 `GithubUpload`
2. 上传到 `/usr/plugins/` 目录
3. 后台启用插件，填写GitHub信息

## ⚙️ 配置说明

### 必需配置（3项）
| 配置项 | 说明 |
|--------|------|
| GitHub用户名 | 你的GitHub用户名 |
| GitHub仓库名 | 存储附件的仓库 |
| GitHub Token | [获取Token](https://github.com/settings/tokens)（需repo权限） |

### 常用配置（2项）
| 配置项 | 说明 |
|--------|------|
| 上传目录 | 默认 `usr/uploads`，自动创建日期子目录 |
| 加速代理 | 国内用户建议开启，提高上传速度 |

### 高级配置（3项）
| 配置项 | 说明 |
|--------|------|
| 仓库分支 | 默认自动检测（main/master） |
| 链接方式 | 推荐「最新版本」（图片无缓存） |
| 本地备份 | 可选同时保存到本地 |

## ❓ 常见问题

**Q：Token怎么获取？**
A：GitHub → Settings → Developer settings → Personal access tokens → Generate new token，勾选 `repo`

**Q：上传速度慢怎么办？**
A：在「常用配置」中开启「加速代理」，选择FastGit或GHProxy

**Q：文件命名规则？**
A：`年月日时分秒_随机ID.扩展名`，如：`202401151230_abc123.jpg`

**Q：如何只上传到GitHub？**
A：在「高级配置」中关闭「本地备份」即可
