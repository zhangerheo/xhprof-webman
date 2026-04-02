<?php
declare(strict_types=1);

namespace Yuanyuanli\Xhprof\Webman;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Class StaticFile
 * @package app\middleware
 */
class XhprofMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $config = config('plugin.yuanyuanli.xhprof.xhprof');
        $xhprof = $config['enable']();

        $extension = extension_loaded('tideways_xhprof');
        // if(false==$extension) return response()->withBody("请安装tideways_xhprof扩展");
        //$redis = extension_loaded("redis");
        //if (false == $redis) return response()->withBody("请安装redis扩展");
        Xhprof::$ignore_url_arr = $config['ignore_url_arr'] ?: "/test";
        Xhprof::$time_limit = $config['time_limit'] ?: 0;
        Xhprof::$log_num = $config['log_num'] ?: 1000;
        Xhprof::$view_wtred = $config['view_wtred'] ?: 3;
        Xhprof::$table_name = $config['table_name'] ?: 'php_monitor';

        if ($xhprof && $extension) Xhprof::xhprofStart();
         // 执行接口逻辑
        $response = $next($request);
         // 停止采集并获取数据
        if ($xhprof && $extension) Xhprof::xhprofStop();
        return $response;
    }
}
