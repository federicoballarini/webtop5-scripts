#!/usr/bin/php
<?php

# check user and domain
if ($argc < 3) {
    echo "ERROR: PLEASE PROVIDE CORRECT USER AND DOMAIN\n";
    die();
}

$user   = $argv[1];
$domain = $argv[2];

# if $user = all sysadmin have to get roundcube password from /etc/rouncubemail/config.inc.php e replace $roundcubepass variable below
$roundcubepass = "yourroundcubepassword";

if ($user != "all") {
    import_filter($user, $domain);
} else {
    $rconnect = mysqli_connect("localhost", "roundcubemail", "$roundcubepass", "roundcubemail");
    if (!$rconnect) {
        echo "ROUNDCUBE DB CONNECTION ERROR\n";
        die();
    }
    
    $queryall = "SELECT username FROM users;";
    $result   = $rconnect->query($queryall);
    
    # call import function only if username not contains * (impersonating) or @ (login with domain). It's necessary to access at least one time with the only username in roundcube and have been enabled users into Webtop
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            if (strpos(trim($row[0]), "*") === FALSE && strpos(trim($row[0]), "@") === FALSE && trim($row[0]) != "root")
                import_filter(trim($row[0]), $domain);
        }
    }
}

function import_filter($user_id, $domain_name)
{
    
    # make complete username
    $complete_user = $user_id . "@" . $domain_name;
    
    # get roundcube filters
    $file = file_get_contents("/var/lib/nethserver/vmail/$complete_user/sieve/roundcube.sieve");
    
    echo "\n***** START USER: $user_id *****\n";
    
    # if empty file or doesn't exist: skip user
    if ($file === false || trim($file) == "/* empty script */") {
        echo "\nRESULT: EMPTY FILE";
        echo "\n\n***** END USER: $user_id *****\n\n\n\n";
        return 0;
    }
    
    $lines = explode("\n", $file);
    $file  = implode("\n", array_slice($lines, 1));
    
    $filters = Array();
    $filters = explode("# r", $file);
    
    # set domain id
    $domain_id = "NethServer";
    $neworder  = 1;
    
    # connect to webtop database
    exec('perl -e \'use NethServer::Password; my $password = NethServer::Password::store("webtop5") ; printf $password;\'', $out2);
    $dpass2 = $out2[0];
    
    $webtop_db = pg_connect("host=localhost port=5432 dbname=webtop5 user=sonicle password=$dpass2");
    
    # read filters from file
    for ($i = 0; $i < count($filters); $i++) {
        if (strpos($filters[$i], 'require') === false && trim($filters[$i]) != "") {
            
            $filtername     = "";
            $enabled        = "t";
            $rule           = "";
            $sieve_match    = "";
            $sieve_rules    = "";
            $sieve_actions  = "";
            $string_filters = "";
            $string_action  = "";
            $error          = false;
            
            echo "\n\n######## START FILTER: $i #########\n\n";
            
            $rowf = explode("\n", $filters[$i]);
            for ($j = 0; $j < count($rowf) && !$error; $j++) {
                $detail = $rowf[$j];
                # get filter name
                if (strpos($detail, 'ule:') !== false) {
                    $filtername = substr(trim($detail), 5, (strlen(trim($detail)) - 6));
                    echo "filter name: " . $filtername . "\n";
                    # get when apply rule
                } else if (strpos($detail, 'if') !== false) {
                    $rulestring = substr(trim($detail), 3);
                    
                    if (substr($rulestring, 0, 5) == "false")
                        $enabled = "f";
                    else
                        $enabled = "t";
                    
                    echo "enabled: " . $enabled . "\n";
                    
                    $rule = substr($rulestring, strpos($rulestring, "#"));
                    
                    if (substr(trim($rule), 0, 2) == "# ")
                        $rule = substr($rule, 2);
                    
                    if ($rule == "true")
                        $sieve_match = "allmsg";
                    else if (substr($rule, 0, 5) == "allof")
                        $sieve_match = "all";
                    else
                        $sieve_match = "any";
                    
                    echo "match: " . $sieve_match . "\n";
                    
                    # if $rule != "true" (always) make rules condition string
                    if ($rule != "true") {
                        
                        $rule      = str_replace(", \"", ",\"", $rule);
                        $rulearray = explode(", ", $rule);
                        
                        for ($z = 0; $z < count($rulearray); $z++) {
                            
                            $fields = explode(":", $rulearray[$z]);
                            $field  = $fields[0];
                            
                            if (substr($field, 0, 5) == "allof" || substr($field, 0, 5) == "anyof")
                                $field = substr($field, 7, strlen($field) - 8);
                            
                            $int_fields = explode("\"", $fields[1]);
                            $cf         = count($int_fields);
                            
                            if ($cf == 5) {
                                $operator = trim($int_fields[0]);
                                $value    = $int_fields[3];
                                
                                if ($int_fields[1] == "subject" || $int_fields[1] == "from" || $int_fields[1] == "to") {
                                    $field    = $int_fields[1];
                                    $argument = "";
                                } else
                                    $argument = $int_fields[1];
                            } else if ($cf == 1) {
                                $field = trim($fields[0]);
                                
                                if ($field == "body")
                                    $argument = "";
                                else
                                    $argument = trim($fields[1]);
                                
                                $int_fields = explode("\"", $fields[2]);
                                
                                $operator = trim($int_fields[0]);
                                $value    = trim($int_fields[1]);
                                
                            } else if ($cf == 7) {
                                $field = "header";
                                
                                $int_fields = explode("\"", $fields[1]);
                                
                                $operator = trim($int_fields[0]);
                                $argument = trim($fields[0]);
                                $value    = trim($int_fields[5]);
                            } else
                                echo "***** NOT IMPLEMENTED *****";
                            
                            $string_filters .= "{\"field\":\"" . rcube2webtop($field) . "\",\"argument\":\"" . rcube2webtop($argument) . "\",\"operator\":\"" . rcube2webtop($operator) . "\",\"value\":\"" . rcube2webtop($value) . "\"},";
                        }
                        
                    } else
                        $sieve_rules = "[]";
                    
                    
                    $sieve_rules = "[" . substr($string_filters, 0, (strlen($string_filters) - 1)) . "]";
                    
                    echo "rules: " . $sieve_rules . "\n";
                    
                    
                    # get actions
                } else if (strpos($detail, '{') !== false) {
                    $string_action = "[";
                } else if (strpos($detail, '{') === false && strpos($detail, '}') === false && $detail != "") {
                    
                    $actionline = explode(" \"", $detail);
                    $method     = trim($actionline[0]);
                    
                    # particular case: flags
                    if (strpos($method, "addflag") !== false) {
                        $arguments = explode(" [\"", $detail);
                        
                        $method   = trim($arguments[0]);
                        $argument = trim($arguments[1]);
                        $argument = substr($argument, 2, (strlen($argument) - 2));
                        $args     = explode(",", $argument);
                        
                        for ($y = 0; $y < count($args); $y++) {
                            if (substr(trim($args[$y]), 0, 3) == "\"\\\\")
                                if (substr(trim($args[$y]), strlen(trim($args[$y])) - 3, 3) == "\"];")
                                    $argument = substr(substr(trim($args[$y]), 3), 0, strlen(substr(trim($args[$y]), 3)) - 3);
                                else
                                    $argument = substr(trim($args[$y]), 3);
                            else
                                $argument = $args[$y];
                            
                            $argument = str_replace("\"", "", $argument);
                            $string_action .= "{\"method\":\"" . rcube2webtop($method) . "\",\"argument\":\"" . rcube2webtop($argument) . "\"},";
                        }
                        
                        # particular case: answer with text
                    } else if (strpos($method, "vacation") !== false) {
                        echo "\n\n---- ERROR: ANSWER WITH TEXT NOT SUPPORTED ----\n\n";
                        $error = true;
                        # classic case
                    } else {
                        $argument = trim(substr($actionline[1], 0, (strlen($actionline[1]) - 2)));
                        $string_action .= "{\"method\":\"" . rcube2webtop($method) . "\",\"argument\":\"" . $argument . "\"},";
                    }
                    
                    # complete actions string
                } else if (strpos($detail, '}') !== false) {
                    $sieve_actions = substr($string_action, 0, (strlen($string_action) - 1)) . "]";
                    echo "actions: " . $sieve_actions . "\n";
                }
                
                
                
            }
            
            # if there was an error do not complete the query
            if (!$error) {
                $order     = 0;
                $query_ord = "SELECT max(\"order\") FROM mail.in_filters WHERE user_id = '$user_id';";
                $result    = pg_query($webtop_db, $query_ord);
                $rowq      = pg_fetch_row($result);
                $order     = $rowq[0];
                
                $neworder += $order;
                
                $filtername    = str_replace("'", "\'", $filtername);
                $sieve_rules   = str_replace("'", "\'", $sieve_rules);
                $sieve_actions = str_replace("'", "\'", $sieve_actions);
                
                $query  = "INSERT INTO mail.in_filters (domain_id,user_id,enabled,\"order\",name,sieve_match,sieve_rules,sieve_actions) VALUES ('$domain_id','$user_id','$enabled','$neworder','$filtername','$sieve_match','$sieve_rules','$sieve_actions');";
                $result = pg_query($webtop_db, $query);
                
                echo "\n\nQUERY: " . $query . "\n";
                
                if ($result === FALSE) {
                    echo "\n\nRESULT: ERROR\n\n";
                } else {
                    echo "\n\nRESULT: OK\n\n";
                }
                $neworder++;
            }
            
            echo "\n######## END FILTER: $i #########\n\n\n";
            
        }
    }
    
    echo "\n\n***** END USER: $user_id *****\n\n\n\n";
    
}

# conversion ended: users now need to open webtop, set webtop5 as default filters manager and proceed with "Save and Close" that write sieve filters file
echo "\n\n ***** CONVERSION ENDED: CHECK ERRORS AND THEN APPLY CHANGES IN WEBTOP USER PAGE ***** \n\n";

# name conversion from roundcube to webtop
function rcube2webtop($word)
{
	
	switch($word){
		
		case "contains": return "contains";
		case "notcontains": return "notcontains";
		case "is": return "equal";
		case "notis": return "notequal";
		case "exists": return "contains";
		case "notexists": return "notcontains";
		
		/* names not completely compatibiles: needs to be verified */
		case "matches": return "matches";
		case "notmatches": return "notmatches";
		case "regex": return "matches";
		case "notregex": return "notmatches";
		
		case "count-gt": return "greaterthan";
		case "count-ge": return "greaterthan";
		case "count-lt": return "lowerthan";
		case "count-le": return "lowerthan";
		case "count-eq": return "equal";
		case "count-ne": return "notequal";
		case "value-gt": return "greaterthan";
		case "value-ge": return "greaterthan";
		case "value-lt": return "lowerthan";
		case "value-le": return "lowerthan";
		case "value-eq": return "equal";
		case "value-ne": return "notequal";
		
		case "subject": return "subject";
		case "from": return "from";
		case "to": return "to";
		case "body": return "body";
		case "size": return "size";
		
		case "fileinto": return "fileinto";
		case "fileinto :copy": return "fileinto";
		case "redirect": return "redirect";
		case "redirect :copy": return "redirect";
		case "reject": return "reject";
		case "vacation": return "keep";  /* there isn't answer with text */
		case "discard": return "discard";
		case "setflag": return "addflag";
		case "addflag": return "addflag";
		case "removeflag": return "keep"; /* there isn't removeflag */
		case "set": return "keep"; /* there isn't set variable */
		case "notify": return "redirect"; /* there isn't send notification: i use forward a copy */
		case "keep": return "keep";
		case "stop": return "stop";
		
		case "Seen": return "seen";
		case "Answered": return "answered";
		case "Flagged": return "flagged";
		case "Deleted": return "deleted";
		case "Draft": return "flagged";
		
		default: return $word;

		
	}
	
}

?>
