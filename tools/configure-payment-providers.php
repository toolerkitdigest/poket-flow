<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function pf_secret_prompt(string $label): string
{
 if(!function_exists('posix_isatty')||!posix_isatty(STDIN)){fwrite(STDERR,"Hidden terminal input is unavailable. No configuration changed.\n");exit(1);}
 $tty=@fopen('/dev/tty','r');if($tty===false){fwrite(STDERR,"Cannot open controlling terminal.\n");exit(1);}
 $off=shell_exec('stty -echo < /dev/tty 2>&1 && printf "__PF_ECHO_OFF__"');if(!is_string($off)||!str_contains($off,'__PF_ECHO_OFF__')){fclose($tty);fwrite(STDERR,"Could not disable terminal echo.\n");exit(1);}
 try{fwrite(STDOUT,$label.': ');$value=fgets($tty);if($value===false)throw new RuntimeException('Input failed.');return trim($value);}finally{shell_exec('stty echo < /dev/tty 2>/dev/null');fwrite(STDOUT,PHP_EOL);fclose($tty);}
}
function pf_choice(string $label,array $choices,string $default): string
{
 fwrite(STDOUT,$label.' ('.implode('/',$choices).') ['.$default.']: ');$value=strtolower(trim((string)fgets(STDIN)));return $value===''?$default:$value;
}
$file='/home/poketflo/private/poketflow-main-database.php';
if(!is_file($file)||!is_readable($file)){fwrite(STDERR,"Private PoketFlow configuration is missing.\n");exit(1);}
$config=require $file;if(!is_array($config)){fwrite(STDERR,"Private configuration is invalid.\n");exit(1);}
fwrite(STDOUT,"Configure payment-provider credentials. Inputs are hidden. Press Enter to keep an existing value.\n");
$mode=pf_choice('PayPal mode',['sandbox','live'],(string)($config['paypal_mode']??'sandbox'));if(!in_array($mode,['sandbox','live'],true)){fwrite(STDERR,"Invalid PayPal mode.\n");exit(1);}
$paystack=pf_secret_prompt('Paystack secret key (blank keeps current)');$paypalId=pf_secret_prompt('PayPal client ID (blank keeps current)');$paypalSecret=pf_secret_prompt('PayPal client secret (blank keeps current)');
if($paystack!==''){if(!str_starts_with($paystack,'sk_test_')&&!str_starts_with($paystack,'sk_live_')){fwrite(STDERR,"Paystack secret key must begin sk_test_ or sk_live_. No file changed.\n");exit(1);}$config['paystack_secret_key']=$paystack;}
if($paypalId!=='')$config['paypal_client_id']=$paypalId;
if($paypalSecret!=='')$config['paypal_client_secret']=$paypalSecret;
$config['paypal_mode']=$mode;
if((empty($config['paystack_secret_key'])||!str_starts_with((string)$config['paystack_secret_key'],'sk_'))&&(!empty($config['paypal_client_id'])||!empty($config['paypal_client_secret']))){if(empty($config['paypal_client_id'])||empty($config['paypal_client_secret'])){fwrite(STDERR,"Both PayPal client ID and secret are required. No file changed.\n");exit(1);}}
$temp=$file.'.tmp.'.bin2hex(random_bytes(6));$php="<?php\n// Private PoketFlow configuration. Never place in public_html.\nreturn ".var_export($config,true).";\n";
if(file_put_contents($temp,$php,LOCK_EX)===false){@unlink($temp);fwrite(STDERR,"Could not write temporary configuration.\n");exit(1);}chmod($temp,0600);
if(!rename($temp,$file)){@unlink($temp);fwrite(STDERR,"Could not safely install provider configuration.\n");exit(1);}chmod($file,0600);fwrite(STDOUT,"Payment-provider configuration updated in the private directory. Secret values were not displayed.\n");
