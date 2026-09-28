# Azure VM nginx site configs

`play.keekii.net` runs on nginx, which **does not read `.htaccess`**. The
front controller (`index.php`) derives the page it renders solely from
`$_GET['link1']`:

```php
$page = 'home';
if (isset($_GET['link1'])) {
    $page = $_GET['link1'];
}
...
include("./sources/$page/content.php");
```

So every pretty URL has to be rewritten to `index.php?link1=<page>` by nginx.
Without those rewrites, `try_files ... /index.php?$query_string` passes the
request through with no `link1`, and **every URL renders the home page** - which
shows up as every video/article/short having the site-wide title instead of its
own.

| file | purpose |
|---|---|
| `sites-available/pumba` | `play.keekii.net` on port 80 (Let's Encrypt HTTP-01) |
| `sites-available/pumba-ssl` | `play.keekii.net` on port 443 |
| `install-nginx-sites.sh` | copies both into `/etc/nginx`, enables them, `nginx -t`, reloads (backs up first, rolls back on failure) |
| `nginx.conf` | generic port-8080 template for the original hosting environment |
| `nginx-fix.sh` | boot-time applier for that original environment (`/home/site/wwwroot` -> `sites-available/default`); it does **not** target the Azure VM layout |

## Applying

```bash
sudo bash deploy/azure/config/install-nginx-sites.sh
```

## Verifying

```bash
curl -sI https://play.keekii.net/watch/<video_id>.html | head -1   # 200
curl -s https://play.keekii.net/watch/<video_id>.html | grep -o '<title>[^<]*' 
curl -sI https://play.keekii.net/shorts | grep -i location          # 302 to first short
```

A video page must return its **own** title. If it returns the site name, the
rewrites are missing.
