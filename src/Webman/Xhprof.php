<?php

declare(strict_types=1);

namespace Yuanyuanli\Xhprof\Webman;

use Yuanyuanli\Xhprof\Webman\XhprofLib\Utils\XHProfRunsDefault;
use Yuanyuanli\Xhprof\Webman\XhprofLib\Display\XhprofDisplay;

class Xhprof
{

    public static $time_limit = 0;  //仅记录响应超过多少秒的请求  默认0记录所有
    public static $ignore_url_arr =["/test"];  //忽略URL配置
    public static $key_prefix = 'xhprof'; //redis前缀
    public static $log_num = 1000;  //仅记录最近的多少次请求(最大值有待观察，看日志、查看响应时间) 默认1000
    public static $view_wtred = 3; //列表耗时超过多少秒标红 默认3s
    public static $ui_html = '../html';
    public static $symbol_lookup_url = "";
    public static $table_name = "";


    public static function getRequest()
    {
      return  request();
    }

    public static function getResponse()
    {
      return  response();
    }
    //页面输出
    public static function index()
    {
        $run = self::getRequest()->get('run');
        $wts = self::getRequest()->get('wts');
        $symbol = self::getRequest()->get('symbol');
        $sort = self::getRequest()->get('sort');
        $run1 = self::getRequest()->get('run1');
        $run2 = self::getRequest()->get('run2');
        $source = self::getRequest()->get('source');
        $params = self::getRequest()->all();
        $echo_page = "<html>";

        $echo_page .= "<head><title>XHProf性能分析报告</title>";
        $echo_page .= XhprofDisplay::xhprof_include_js_css(self::$ui_html);
        $echo_page .= "</head>";
        $echo_page .= "<body>";
        $echo_page .= XhprofDisplay::displayXHProfReport(
            $params,
            $source,
            $run,
            $wts,
            $symbol,
            $sort,
            $run1,
            $run2
        );
        $echo_page .= "</body>";
        $echo_page .= "</html>";
        return $echo_page;
    }

    //监听入口
    public static function xhprofStart()
    {
        self::_init();
        
        // 兼容标准xhprof和tideways_xhprof扩展
        if (extension_loaded('xhprof')) {
            \xhprof_enable(\XHPROF_FLAGS_NO_BUILTINS + \XHPROF_FLAGS_CPU + \XHPROF_FLAGS_MEMORY);
        } elseif (extension_loaded('tideways_xhprof')) {
            \tideways_xhprof_enable(\TIDEWAYS_XHPROF_FLAGS_NO_BUILTINS | \TIDEWAYS_XHPROF_FLAGS_CPU | \TIDEWAYS_XHPROF_FLAGS_MEMORY);
        }
    }

    public static function xhprofStop()
    {
        // 兼容标准xhprof和tideways_xhprof扩展
        if (extension_loaded('xhprof')) {
            $xhprof_data = \xhprof_disable();
        } elseif (extension_loaded('tideways_xhprof')) {
            $xhprof_data = \tideways_xhprof_disable();
        } else {
            return;
        }

        XHProfRunsDefault::save_run($xhprof_data, "xhprof_foo");
    }

    protected static function _init()
    {
        date_default_timezone_set('PRC');
        // 检查标准xhprof扩展或tideways_xhprof扩展
        $extension = extension_loaded('xhprof') || extension_loaded('tideways_xhprof');
        if(false==$extension) return false;// self::getResponse()->withBody("请安装xhprof扩展或tideways_xhprof扩展");
        $redis=extension_loaded("redis");
        if(false==$redis) return false;// self::getResponse()->withBody("请安装redis扩展");
    }
}
