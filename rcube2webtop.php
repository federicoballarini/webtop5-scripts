#!/usr/bin/php
<?php

# controllo utente e dominio
if ($argc < 3) {
    echo "ERROR: PLEASE PROVIDE CORRECT USER AND DOMAIN\n";
    die();
}

$user   = $argv[1];
$domain = $argv[2];

# se tutti gli utenti mi connetto a roundcube: l'utente deve recuperare la password da /etc/roundcubemail/config.inc.php e sostituirla qui sotto
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
    
    # chiamo la funzione di importazione solo se utente non contiene * (impersonating) e non contiene la @ (accesso con dominio). è bene aver effettuato l'accesso almeno una volta solo con utente in roundcube e aver abilitato l'account su webtop
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            if (strpos(trim($row[0]), "*") === FALSE && strpos(trim($row[0]), "@") === FALSE && trim($row[0]) != "root")
                import_filter(trim($row[0]), $domain);
        }
    }
}

function import_filter($user_id, $domain_name)
{
    
    # compongo username completa
    $complete_user = $user_id . "@" . $domain_name;
    
    # recupero filtri roundcube
    $file = file_get_contents("/var/lib/nethserver/vmail/$complete_user/sieve/roundcube.sieve");
    
    echo "\n***** UTENTE: $user_id *****\n";
    
    # se il file è vuoto o non esiste skippo l'utente
    if ($file === false || trim($file) == "/* empty script */") {
        echo "\nRESULT: EMPTY FILE";
        echo "\n\n***** FINE UTENTE: $user_id *****\n\n\n\n";
        return 0;
    }
    
    $lines = explode("\n", $file);
    $file  = implode("\n", array_slice($lines, 1));
    
    $filters = Array();
    $filters = explode("# r", $file);
    
    # imposto domain id
    $domain_id = "NethServer";
    $neworder  = 1;
    
    # eseguo connessione a db webtop
    exec('perl -e \'use NethServer::Password; my $password = NethServer::Password::store("webtop5") ; printf $password;\'', $out2);
    $dpass2 = $out2[0];
    
    $webtop_db = pg_connect("host=localhost port=5432 dbname=webtop5 user=sonicle password=$dpass2");
    
    # leggo i filtri
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
            
            echo "\n\n######## FILTRO $i #########\n\n";
            
            $rowf = explode("\n", $filters[$i]);
            for ($j = 0; $j < count($rowf) && !$error; $j++) {
                $detail = $rowf[$j];
                # recupero nome filtro
                if (strpos($detail, 'ule:') !== false) {
                    $filtername = substr(trim($detail), 5, (strlen(trim($detail)) - 6));
                    echo "nome filtro: " . $filtername . "\n";
                    # recupero quando applicare la regola
                } else if (strpos($detail, 'if') !== false) {
                    $rulestring = substr(trim($detail), 3);
                    
                    if (substr($rulestring, 0, 5) == "false")
                        $enabled = "f";
                    else
                        $enabled = "t";
                    
                    echo "abilitato: " . $enabled . "\n";
                    
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
                    
                    # se diverso da sempre compongo la stringa delle regole
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
                    
                    
                    # recupero le azioni da compiere
                } else if (strpos($detail, '{') !== false) {
                    $string_action = "[";
                } else if (strpos($detail, '{') === false && strpos($detail, '}') === false && $detail != "") {
                    
                    $actionline = explode(" \"", $detail);
                    $method     = trim($actionline[0]);
                    
                    # caso particolare coi flag e contrassegni
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
                        
                        # caso particolare rispondi con testo
                    } else if (strpos($method, "vacation") !== false) {
                        echo "\n\n---- ERROR: ANSWER WITH TEXT NOT SUPPORTED ----\n\n";
                        $error = true;
                        # caso classico
                    } else {
                        $argument = trim(substr($actionline[1], 0, (strlen($actionline[1]) - 2)));
                        $string_action .= "{\"method\":\"" . rcube2webtop($method) . "\",\"argument\":\"" . $argument . "\"},";
                    }
                    
                    # completo la stringa delle azioni
                } else if (strpos($detail, '}') !== false) {
                    $sieve_actions = substr($string_action, 0, (strlen($string_action) - 1)) . "]";
                    echo "actions: " . $sieve_actions . "\n";
                }
                
                
                
            }
            
            # se c'è stato un errore (ad esempio si tratta di un rispondi con messaggio) non completo la query
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
            
            echo "\n######## FINE FILTRO $i #########\n\n\n";
            
        }
    }
    
    echo "\n\n***** FINE UTENTE: $user_id *****\n\n\n\n";
    
}

# messaggio di conversione completata: l'utente ora deve aprire la propria pagina, impostare come gestore principale dei filtri webtop e procedere con un Salva e Chiudi che scrive il file dei filtri sieve
echo "\n\n ***** CONVERSION ENDED: CHECK ERRORS AND THEN APPLY CHANGES IN WEBTOP USER PAGE ***** \n\n";

# conversione nomi da roundcube a webtop
function rcube2webtop($word)
{
	
	switch($word){
		
		case "contains": return "contains";
		case "notcontains": return "notcontains";
		case "is": return "equal";
		case "notis": return "notequal";
		case "exists": return "contains";
		case "notexists": return "notcontains";
		
		/* nomi non completamente compatibili: necessitano una verifica */
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
		case "vacation": return "keep";  /* non c'è rispondi con messaggio */
		case "discard": return "discard";
		case "setflag": return "addflag";
		case "addflag": return "addflag";
		case "removeflag": return "keep"; /* non c'è rimuovi flag */
		case "set": return "keep"; /* non c'è imposta variabile */
		case "notify": return "redirect"; /* non c'è invia notifica: inoltro copia */
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
