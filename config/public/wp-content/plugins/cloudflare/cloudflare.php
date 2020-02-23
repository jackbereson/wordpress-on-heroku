<?php
/*
Plugin Name: CloudFlare
Plugin URI: http://www.cloudflare.com/wiki/CloudFlareWordPressPlugin
Description: CloudFlare integrates your blog with the CloudFlare platform.
Version: 1.3.10
Author: Ian Pye, Jerome Chen, James Greene (CloudFlare Team)
License: GPLv2
*/

/*
This program is free software; you can redistribute it and/or modify 
it under the terms of the GNU General Public License as published by 
the Free Software Foundation; version 2 of the License.

This program is distributed in the hope that it will be useful, 
but WITHOUT ANY WARRANTY; without even the implied warranty of 
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the 
GNU General Public License for more details. 

You should have received a copy of the GNU General Public License 
along with this program; if not, write to the Free Software 
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA 

Plugin adapted from the Akismet WP plugin.

*/	

define('CLOUDFLARE_VERSION', '1.3.10');
require_once("ip_in_range.php");

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
	exit;
}

function cloudflare_init() {
    global $cf_api_host, $cf_api_port, $is_cf;

    $cf_api_host = "ssl://www.cloudflare.com";
    $cf_api_port = 443;
    
    $is_cf = ($_SERVER["HTTP_CF_CONNECTING_IP"])? TRUE: FALSE;    

    if (strpos($_SERVER["REMOTE_ADDR"], ":") === FALSE) {
        $cf_ip_ranges = array("204.93.240.0/24","204.93.177.0/24","199.27.128.0/21","173.245.48.0/20","103.21.244.0/22","103.22.200.0/22","103.31.4.0/22","141.101.64.0/18","108.162.192.0/18","190.93.240.0/20","188.114.96.0/20","197.234.240.0/22","198.41.128.0/17","162.158.0.0/15");
        // IPV4: Update the REMOTE_ADDR value if the current REMOTE_ADDR value is in the specified range.
        foreach ($cf_ip_ranges as $range) {
            if (ipv4_in_range($_SERVER["REMOTE_ADDR"], $range)) {
                if ($_SERVER["HTTP_CF_CONNECTING_IP"]) {
                    $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_CF_CONNECTING_IP"];
                }
                break;
            }
        }        
    }
    else {
        $cf_ip_ranges = array("2400:cb00::/32", "2606:4700::/32", "2803:f800::/32");
        $ipv6 = get_ipv6_full($_SERVER["REMOTE_ADDR"]);
        foreach ($cf_ip_ranges as $range) {
            if (ipv6_in_range($ipv6, $range)) {
                if ($_SERVER["HTTP_CF_CONNECTING_IP"]) {
                    $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_CF_CONNECTING_IP"];
                }
                break;
            }
        }
    }
        
    // Let people know that the CF WP plugin is turned on.
    if (!headers_sent()) {
        header("X-CF-Powered-By: WP " . CLOUDFLARE_VERSION);
    }
    add_action('admin_menu', 'cloudflare_config_page');
    cloudflare_admin_warnings();
}
add_action('init', 'cloudflare_init',1);

function cloudflare_admin_init() {
    
}

add_action('admin_init', 'cloudflare_admin_init');

function cloudflare_config_page() {
	if ( function_exists('add_submenu_page') ) {
		add_submenu_page('plugins.php', __('CloudFlare Configuration'), __('CloudFlare'), 'manage_options', 'cloudflare', 'cloudflare_conf');
    }
}

function load_cloudflare_keys () {
    global $cloudflare_api_key, $cloudflare_api_email;
    if (!$cloudflare_api_key) {
        $cloudflare_api_key = get_option('cloudflare_api_key');
    }
    if (!$cloudflare_api_email) {
        $cloudflare_api_email = get_option('cloudflare_api_email');
    }
}

function cloudflare_conf() {
    if ( function_exists('current_user_can') && !current_user_can('manage_options') )
        die(__('Cheatin&#8217; uh?'));
    global $cloudflare_api_key, $cloudflare_api_email, $is_cf;
    global $wpdb;

    // get raw domain - may include www.
    $urlparts = parse_url(site_url());
    $raw_domain = $urlparts["host"];

    $curl_installed = function_exists('curl_init');

    if ($curl_installed) {
        // Attempt to get the matching host from CF
        $domain = get_domain($cloudflare_api_key, $cloudflare_api_email, $raw_domain);
        // If not found, default to pulling the domain via client side.
        if (!$domain) {
             $domain = $raw_domain;
        }
    }
    else {
         $domain = $raw_domain;    
    }
    
    define ("THIS_DOMAIN",  $domain);

    $db_results = array();
               
	if ( isset($_POST['submit']) 
         && check_admin_referer('cloudflare-db-api','cloudflare-db-api-nonce') ) {
        
		if ( function_exists('current_user_can') && !current_user_can('manage_options') ) {
			die(__('Cheatin&#8217; uh?'));
        }

		$key = $_POST['key'];
		$email = $_POST['email'];
        $dev_mode = esc_sql($_POST["dev_mode"]);

		if ( empty($key) ) {
			$key_status = 'empty';
			$ms[] = 'new_key_empty';
			delete_option('cloudflare_api_key');
		} else {
            $ms[] = 'new_key_valid';
			update_option('cloudflare_api_key', esc_sql($key));
            update_option('cloudflare_api_key_set_once', "TRUE");
        }

		if ( empty($email) || !is_email($email) ) {
			$email_status = 'empty';
			$ms[] = 'new_email_empty';
			delete_option('cloudflare_api_email');
		} else {
			$ms[] = 'new_email_valid';
			update_option('cloudflare_api_email', esc_sql($email));
            update_option('cloudflare_api_email_set_once', "TRUE");
        }


        $messages = array(
                          'new_key_empty' => array('color' => 'aa0', 'text' => __('Your key has been cleared.')),
                          'new_key_valid' => array('color' => '2d2', 'text' => __('Your key has been verified. Happy blogging!')),
                          'new_email_empty' => array('color' => 'aa0', 'text' => __('Your email has been cleared.')),
                          'new_email_valid' => array('color' => '2d2', 'text' => __('Your email has been verified. Happy blogging!'))
                          );

        if ($curl_installed) {
            if ($key != "" && $email != "") {
                set_dev_mode(esc_sql($key), esc_sql($email), THIS_DOMAIN, $dev_mode);
                if ($dev_mode) {
                    $ms[] = 'dev_mode_on';
                }
                else {
                    $ms[] = 'dev_mode_off';
                }
            }
        
            $messages['dev_mode_on'] = array('color' => '2d2', 'text' => __('Development mode is On. Happy blogging!'));
            $messages['dev_mode_off'] = array('color' => 'aa0', 'text' => __('Development mode is Off. Happy blogging!'));
        }
    }
    ?>
    <?php if ( !empty($_POST['submit'] )) { ?>
    <div id="message" class="updated fade"><p><strong><?php _e('Options saved.') ?></strong></p></div>
    <?php } ?>
    <div class="wrap">

    <?php if ($is_cf) { ?>
        <h3>You are currently using CloudFlare!</h3>
    <?php } ?>

    <h4><?php _e('CLOUDFLARE WORDPRESS PLUGIN:'); ?></h4>
        <?php //    <div class="narrow"> ?>

CloudFlare has developed a plugin for WordPress. By using the CloudFlare WordPress Plugin, you receive: 
<ol>
<li>Correct IP Address information for comments posted to your site</li>
<li>Better protection as spammers from your WordPress blog get reported to CloudFlare</li>
</ol>

<h4>VERSION COMPATIBILITY:</h4>

The plugin is compatible with WordPress version 2.8.6 and later. The plugin will not install unless you have a compatible platform.

<h4>THINGS YOU NEED TO KNOW:</h4>

<ol>
<li>The main purpose of this plugin is to ensure you have no change to your originating IPs when using CloudFlare. Since CloudFlare acts a reverse proxy, connecting IPs now come from CloudFlare's range. This plugin will ensure you can continue to see the originating IP. Once you install the plugin, the IP benefit will be activated.</li>
 
<li>Every time you click the 'spam' button on your blog, this threat information is sent to CloudFlare to ensure you are constantly getting the best site protection.</li>

<li>We recommend that any user on CloudFlare with WordPress use this plugin. </li>

<li>NOTE: This plugin is complementary to Akismet and W3 Total Cache. We recommend that you continue to use those services.</li> 

</ol>

<h4>MORE INFORMATION ON CLOUDFLARE:</h4>

CloudFlare is a service that makes websites load faster and protects sites from online spammers and hackers. Any website with a root domain (ie www.mydomain.com) can use CloudFlare. On average, it takes less than 5 minutes to sign up. You can learn more here: <a href="http://www.cloudflare.com/">CloudFlare.com</a>.

    <?php 
        if ($curl_installed) {
            $dev_mode = get_dev_mode_status($cloudflare_api_key, $cloudflare_api_email, THIS_DOMAIN);
        }
    ?>

    <hr />

    <form action="" method="post" id="cloudflare-conf">
    <?php wp_nonce_field('cloudflare-db-api','cloudflare-db-api-nonce'); ?>
    <?php if (get_option('cloudflare_api_key') && get_option('cloudflare_api_email')) { ?>
    <?php } else { ?> 
        <p><?php printf(__('Input your API key from your CloudFlare Accounts Settings page here. To find your API key, log in to <a href="%1$s">CloudFlare</a> and go to \'Account\'.'), 'https://www.cloudflare.com/my-account.html'); ?></p>
    <?php } ?>
    <?php if ($ms) { foreach ( $ms as $m ) { ?>
    <p style="padding: .5em; color: #<?php echo $messages[$m]['color']; ?>; font-weight: bold;"><?php echo $messages[$m]['text']; ?></p>
    <?php } } ?>
    <h3><label for="key"><?php _e('CloudFlare API Key'); ?></label></h3>
    <p><input id="key" name="key" type="text" size="50" maxlength="48" value="<?php echo get_option('cloudflare_api_key'); ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" /> (<?php _e('<a href="https://www.cloudflare.com/my-account.html">Get this?</a>'); ?>)</p>
    <h3><label for="email"><?php _e('CloudFlare API Email'); ?></label></h3>
    <p><input id="email" name="email" type="text" size="50" maxlength="48" value="<?php echo get_option('cloudflare_api_email'); ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" /> (<?php _e('<a href="https://www.cloudflare.com/my-account.html">Get this?</a>'); ?>)
    <h3><label for="dev_mode"><?php _e('Development Mode'); ?></label> <span style="font-size:9pt;">(<a href="https://support.cloudflare.com/entries/22280726-what-does-cloudflare-development-mode-mean" " target="_blank">What is this?</a>)</span></h3>

    <?php if ($curl_installed) { ?>
    <div style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;">
    <input type="radio" name="dev_mode" value="0" <? if ($dev_mode == "off") echo "checked"; ?>> Off
    <input type="radio" name="dev_mode" value="1" <? if ($dev_mode == "on") echo "checked"; ?>> On
    </div>
    <?php } else { ?>
    You cannot toggle development mode because cURL is not installed for your domain.  Please contact a server administrator for assistance with installing cURL.
    <?php } ?>
    
    </p>
    <p class="submit"><input type="submit" name="submit" value="<?php _e('Update options &raquo;'); ?>" /></p>
    </form>

        <?php //    </div> ?>
    </div>
    <?php
}

function cloudflare_admin_warnings() {
    
    global $cloudflare_api_key, $cloudflare_api_email; 
    load_cloudflare_keys();    
}

// Now actually allow CF to see when a comment is approved/not-approved.
function cloudflare_set_comment_status($id, $status) {
    global $cf_api_host, $cf_api_port, $cloudflare_api_key, $cloudflare_api_email; 
    if (!$cf_api_host || !$cf_api_port) {
        return;
    }
    load_cloudflare_keys();
    if (!$cloudflare_api_key || !$cloudflare_api_email) {
        return;
    }

    // ajax/external-event.html?email=ian@cloudflare.com&t=94606855d7e42adf3b9e2fd004c7660b941b8e55aa42d&evnt_v={%22dd%22:%22d%22}&evnt_t=WP_SPAM
    $comment = get_comment($id);
    $value = array("a" => $comment->comment_author, 
                   "am" => $comment->comment_author_email,
                   "ip" => $comment->comment_author_IP,
                   "con" => substr($comment->comment_content, 0, 100));
    $url = "/ajax/external-event.html?evnt_v=" . urlencode(json_encode($value)) . "&u=$cloudflare_api_email&tkn=$cloudflare_api_key&evnt_t=";
     
    // If spam, send this info over to CloudFlare.
    if ($status == "spam") {
        $url .= "WP_SPAM";
        $fp = @fsockopen($cf_api_host, $cf_api_port, $errno, $errstr, 30);
        if ($fp) {
            $out = "GET $url HTTP/1.1\r\n";
            $out .= "Host: www.cloudflare.com\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            $res = "";
            while (!feof($fp)) {
                $res .= fgets($fp, 128);
            }
            fclose($fp);
        }
    }
}

add_action('wp_set_comment_status', 'cloudflare_set_comment_status', 1, 2);

function get_dev_mode_status($token, $email, $zone) {
    $url = 'https://www.cloudflare.com/api_json.html';
    $fields = array(
                'a'=>"zone_load",
                'tkn'=>$token,
                'email'=>$email,
                'z'=>$zone
              );

    foreach($fields as $key=>$value) { 
        $fields_string .= $key.'='.$value.'&';
    }
    rtrim($fields_string,'&');
    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_POST,count($fields));
    curl_setopt($ch,CURLOPT_POSTFIELDS,$fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $result = curl_exec($ch);
    $result = json_decode($result);
    curl_close($ch);

    if ($result->response->zone->obj->zone_status_class == "status-dev-mode") {
        return "on";
    }

    return "off";
}

function set_dev_mode($token, $email, $zone, $value)
{
    $url = 'https://www.cloudflare.com/api_json.html';
    $fields = array(
            'a'=>"devmode",
            'tkn'=>$token,
            'email'=>$email,
            'z'=>$zone,
            'v'=>$value
          );
    foreach($fields as $key=>$value) { 
        $fields_string .= $key.'='.$value.'&';
    }
    rtrim($fields_string,'&');
    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_POST,count($fields));
    curl_setopt($ch,CURLOPT_POSTFIELDS,$fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $result = curl_exec($ch);
    $result = json_decode($result);
    curl_close($ch);
}

function get_domain($token, $email, $raw_domain) {
    $url = 'https://www.cloudflare.com/api_json.html';
    $fields = array(
                'a'=>"zone_load_multi",
                'tkn'=>$token,
                'email'=>$email
              );

    foreach($fields as $key=>$value) { 
        $fields_string .= $key.'='.$value.'&';
    }
    rtrim($fields_string,'&');
    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_POST,count($fields));
    curl_setopt($ch,CURLOPT_POSTFIELDS,$fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $result = curl_exec($ch);
    $result = json_decode($result);
    curl_close($ch);

    $zone_count = $result->response->zones->count;
    if ($zone_count > 0) {
        for ($i = 0; $i < $zone_count; $i++) {
            $zone_name = $result->response->zones->objs[$i]->zone_name;
            if (strpos($raw_domain, $zone_name) !== FALSE){
                return $zone_name;
            }
        }
    }
    
    return null;
}
?>
