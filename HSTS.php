<?php
 
/**
 * Extension enabling the HSTS on a MediaWiki website on a per-user basis
 * 
 * Note if you intend to activate HSTS on the whole website, it will be more efficient and robust
 * to add it directly in the server configuration
 * 
 * @file
 * @ingroup Extensions
 * @author Seb35
 * @licence WTFPL 2.0
 * /
 
/* Options */
$wgDefaultUserOptions['hsts'] = 0; // Default value of HSTS for anonymous visitors and newly created accounts
$wgHSTSMaxAge = 30*86400;          // max-age parameter for HSTS; can be either:
                                   //  - a number (canonical number of seconds before expiration of HSTS); or
                                   //  - a date when HSTS will expire (e.g. just before certificate expiration).
                                   // Note that in the second case the header is dynamical, so you may want to
                                   //  configure accordingly your cache servers for a consistent user experience,
                                   //  particularly given the authoritative HSTS header is the last sent, even if shorter.
$wgHSTSIncludeSubdomains = false;  // includeSubDomains parameter for HSTS; boolean
 
 
/* Register hooks */
 
$wgExtensionCredits['other'][] = array(
        'path' => __FILE__,
        'name' => 'HSTS',
        'author' => 'Seb35',
        'version' => '0.1',
        'url' => 'https://www.mediawiki.org/wiki/Extension:HSTS',
        'descriptionmsg' => 'hsts-ext-desc',
);
 
$dir = dirname( __FILE__ ) . '/';
$wgExtensionMessagesFiles['HSTS'] = $dir . 'HSTS.i18n.php';
 
$wgHooks['GetPreferences'][] = 'HSTSPreference';
$wgHooks['BeforePageDisplay'][] = 'HSTSAddHeader';
 
 
/* Code */
 
function HSTSPreference( $user, &$preferences ) {
        $preferences['hsts'] = array(
                'type' => 'toggle',
                'label-message' => 'hsts-tog',
                'section' => 'personal/info',
        );
        return true;
}
 
function HSTSAddHeader( $output ) {
        global $wgHSTSIncludeSubdomains, $wgHSTSMaxAge;
        if( $output->getRequest()->detectProtocol() != 'https' || !$output->getUser()->getOption( 'hsts' ) ) return true;
        $maxage = 0;
        if( is_int( $wgHSTSMaxAge ) ) $maxage = max($wgHSTSMaxAge,0);
        else {
                $maxage = wfTimestamp( TS_UNIX, $wgHSTSMaxAge );
                if( $maxage !== false ) $maxage -= wfTimestamp();
                else {
                        wfDebug( '[HSTS] Bad value of the parameter $wgHSTSMaxAge: must be an integer or a date.' );
                        return true;
                }
                if( $maxage < 0 ) {
                        wfDebug( '[HSTS] Expired date; HSTS has been lost for all users, apart if externally added in the server configuration.' );
                        return true;
                }
        }
        $output->getRequest()->response()->header( 'Strict-Transport-Security: max-age='.$maxage.($wgHSTSIncludeSubdomains?'; includeSubDomains':'') );
        wfDebug( '[HSTS] Strict-Transport-Security: max-age='.$maxage.($wgHSTSIncludeSubdomains?'; includeSubDomains':'') );
        return true;
}
