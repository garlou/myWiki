<?php
/**
 * auth/basic.class.php
 *
 * foundation authorisation class 
 * all auth classes should inherit from this class
 *
 * @author    Chris Smith <chris@jalakaic.co.uk>
 */
 
class auth_ldap extends auth_basic {
    var $cnf = null;
    var $con = null;

    /**
     * Constructor
     */
    function auth_ldap(){
        global $conf;
        $this->cnf = $conf['auth']['ldap'];
        if(empty($this->cnf['groupkey'])) $this->cnf['groupkey'] = 'cn';
    }


	/**
	 * Check user+password
	 *
	 * Checks if the given user exists and the given
	 * plaintext password is correct by trying to bind
     * to the LDAP server
	 *
	 * @author  Andreas Gohr <andi@splitbrain.org>
	 * @return  bool
	 */
	function checkPass($user,$pass){
        // reject empty password
        if(empty($pass)) return false;
        if(!$this->_openLDAP()) return false;

        // indirect user bind
        if($this->cnf['binddn'] && $this->cnf['bindpw']){
            // use superuser credentials
            if(!@ldap_bind($this->con,$this->cnf['binddn'],$this->cnf['bindpw'])){
                if($this->cnf['debug'])
                    msg('LDAP bind as superuser: '.htmlspecialchars(ldap_error($this->con)),0);
                return false;
            }

        }else if($this->cnf['binddn'] &&
                 $this->cnf['usertree'] &&
                 $this->cnf['userfilter']) {
            // special bind string
            $dn = $this->_makeFilter($this->cnf['binddn'],
                                     array('user'=>$user,'server'=>$this->cnf['server']));

        }else if(strpos($cnf['usertree'], '%{user}')) {
            // direct user bind
            $dn = $this->_makeFilter($this->cnf['usertree'],
                                     array('user'=>$user,'server'=>$this->cnf['server']));

        }else{
            // Anonymous bind
            if(!@ldap_bind($this->con)){
                msg("LDAP: can not bind anonymously",-1);
                if($this->cnf['debug'])
                    msg('LDAP anonymous bind: '.htmlspecialchars(ldap_error($this->con)),0);
                return false;
            }
        }

        // Try to bind to with the dn if we have one.
        if(!empty($dn)) {
            // User/Password bind
            if(!@ldap_bind($this->con,$dn,$pass)){
                if($this->cnf['debug']){
                    msg("LDAP: bind with $dn failed", -1);
                    msg('LDAP user dn bind: '.htmlspecialchars(ldap_error($this->con)),0);
                }
                return false;
            }
            return true;
        }else{
            // See if we can find the user
            $info = $this->getUserData($user);
            if(empty($info['dn'])) {
                return false;
            } else {
                $dn = $info['dn'];
            }

            // Try to bind with the dn provided
            if(!@ldap_bind($this->con,$dn,$pass)){
                if($this->cnf['debug']){
                    msg("LDAP: bind with $dn failed", -1);
                    msg('LDAP user bind: '.htmlspecialchars(ldap_error($this->con)),0);
                }
                return false;
            }
            return true;
        }

        return false;
	}
	
	/**
	 * Return user info [ MUST BE OVERRIDDEN ]
	 *
	 * Returns info about the given user needs to contain
	 * at least these fields:
	 *
	 * name string  full name of the user
	 * mail string  email addres of the user
	 * grps array   list of groups the user is in
     *
     * This LDAP specific function returns the following
     * addional fields:
     *
     * dn   string  distinguished name (DN)
     * uid  string  Posix User ID
     *
     * @author  Andreas Gohr <andi@splitbrain.org>
     * @author  Trouble
     * @author  Dan Allen <dan.j.allen@gmail.com>
     * @auhtor  <evaldas.auryla@pheur.org>
	 * @return  array containing user data or false
     */
	function getUserData($user) {
        global $conf;
        if(!$this->_openLDAP()) return false;

        $info['user']   = $user;
        $info['server'] = $this->cnf['server'];

        //get info for given user
        $base = $this->_makeFilter($this->cnf['usertree'], $info);
        if(!empty($this->cnf['userfilter'])) {
            $filter = $this->_makeFilter($this->cnf['userfilter'], $info);
        } else {
            $filter = "(ObjectClass=*)";
        }

        $sr     = @ldap_search($this->con, $base, $filter);
        $result = @ldap_get_entries($this->con, $sr);
        if($this->cnf['debug'])
            msg('LDAP user search: '.htmlspecialchars(ldap_error($this->con)),0);

        // Don't accept more or less than one response
        if($result['count'] != 1){
            return false; //user not found
        }

        $user_result = $result[0];
        ldap_free_result($sr);

        // general user info
        $info['dn']   = $user_result['dn'];
        $info['mail'] = $user_result['mail'][0];
        $info['name'] = $user_result['cn'][0];
        $info['grps'] = array();

        // overwrite if other attribs are specified.
        if(is_array($this->cnf['mapping'])){
            foreach($this->cnf['mapping'] as $localkey => $key) {
                if(is_array($key)) {
                    // use regexp to clean up user_result
                    list($key, $regexp) = each($key);
                    foreach($user_result[$key] as $grp){
                        if (preg_match($regexp,$grp,$match)) {
                            if($localkey == 'grps') {
                                $info[$localkey][] = $match[1];
                            } else {
                                $info[$localkey] = $match[1];
                            }
                        }
                    }
                } else {
                    $info[$localkey] = $user_result[$key][0];
                }
            }
        }
        $user_result = array_merge($info,$user_result);

        //get groups for given user if grouptree is given
        if ($this->cnf['grouptree'] && $this->cnf['groupfilter']) {
            $base   = $this->_makeFilter($this->cnf['grouptree'], $user_result);
            $filter = $this->_makeFilter($this->cnf['groupfilter'], $user_result);

            $sr = @ldap_search($this->con, $base, $filter, array($this->cnf['groupkey']));
            if(!$sr){
                msg("LDAP: Reading group memberships failed",-1);
                if($this->cnf['debug'])
                    msg('LDAP group search: '.htmlspecialchars(ldap_error($this->con)),0);
                return false;
            }
            $result = ldap_get_entries($this->con, $sr);
            ldap_free_result($sr);

            foreach($result as $grp){
                if(!empty($grp[$this->cnf['groupkey']][0]))
                    $info['grps'][] = $grp[$this->cnf['groupkey']][0];
            }
        }

        // always add the default group to the list of groups
        if(!in_array($conf['defaultgroup'],$info['grps'])){
            $info['grps'][] = $conf['defaultgroup'];
        }

        return $info;
	}
	
    /**
     * Make LDAP filter strings.
     *
     * Used by auth_getUserData to make the filter
     * strings for grouptree and groupfilter
     *
     * filter      string  ldap search filter with placeholders
     * placeholders array   array with the placeholders
     * 
     * @author  Troels Liebe Bentsen <tlb@rapanden.dk>
     * @return  string
     */
    function _makeFilter($filter, $placeholders) {
        preg_match_all("/%{([^}]+)/", $filter, $matches, PREG_PATTERN_ORDER);
        //replace each match
        foreach ($matches[1] as $match) {
            //take first element if array
            if(is_array($placeholders[$match])) {
                $value = $placeholders[$match][0];
            } else {
                $value = $placeholders[$match];
            }
            $filter = str_replace('%{'.$match.'}', $value, $filter);
        }
        return $filter;
    } 

    /**
     * Opens a connection to the configured LDAP server and sets the wnated
     * option on the connection
     *
     * @author  Andreas Gohr <andi@splitbrain.org>
     */
    function _openLDAP(){
        if($this->con) return true; // connection already established

        if(!$this->cnf['port']) $port = 636;
        $this->con = @ldap_connect($this->cnf['server'],$this->cnf['port']);
        if(!$this->con){
            msg("LDAP: couldn't connect to LDAP server",-1);
            return false;
        }

        //set protocol version and dependend options
        if($this->cnf['version']){
            if(!@ldap_set_option($this->con, LDAP_OPT_PROTOCOL_VERSION,
                                 $this->cnf['version'])){
                msg('Setting LDAP Protocol version '.$this->cnf['version'].' failed',-1);
                if($this->cnf['debug'])
                    msg('LDAP version set: '.htmlspecialchars(ldap_error($this->con)),0);
            }else{
                //use TLS (needs version 3)
                if($this->cnf['starttls']) {
                    if (!@ldap_start_tls($this->con)){
                        msg('Starting TLS failed',-1);
                        if($this->cnf['debug'])
                            msg('LDAP TLS set: '.htmlspecialchars(ldap_error($this->con)),0);
                    }
                }
                // needs version 3
                if(isset($this->cnf['referrals'])) {
                    if(!@ldap_set_option($this->con, LDAP_OPT_REFERRALS,
                       $this->cnf['referrals'])){
                        msg('Setting LDAP referrals to off failed',-1);
                        if($this->cnf['debug'])
                            msg('LDAP referal set: '.htmlspecialchars(ldap_error($this->con)),0);
                    }
                }
            }
        }

        //set deref mode
        if($this->cnf['deref']){
            if(!@ldap_set_option($this->con, LDAP_OPT_DEREF, $this->cnf['deref'])){
                msg('Setting LDAP Deref mode '.$this->cnf['deref'].' failed',-1);
                if($this->cnf['debug'])
                    msg('LDAP deref set: '.htmlspecialchars(ldap_error($this->con)),0);
            }
        }

        return true;
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :