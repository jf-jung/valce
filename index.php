<!DOCTYPE html>
<html>
<head>
	<title></title>
</head>
<body>
	<form>
		<button type="submit" name="cursus" value="ARH">ARH</button>
		<button type="submit" name="cursus" value="DL">DL</button>
		<button type="submit" name="cursus" value="CDI">CDI</button>
	</form>
</body>
</html>




<?php
if (isset($_GET['cursus']) && $cursus = $_GET['cursus']) {
#################################################
# curl init, with options and cookies           #
#################################################

$curl = curl_init();
$path_cookie = 'valce';
curl_setopt($curl, CURLOPT_COOKIEJAR, realpath($path_cookie));
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);

## CSV init stuff
$path_csv = 'jurys-'.$cursus.'-'.date('Ymd').'.csv';
if (!file_exists(realpath($path_csv))) touch($path_csv);
$csv_file = fopen($path_csv, 'w');
fputcsv($csv_file, ['Nom', 'Prénom', 'Adresse', 'Code Postal', 'Ville', 'Téléphone perso', 'Téléphone pro', 'Mail', ], ";");

#################################################
# Connecting to VALCE                           #
#################################################
$con = parse_ini_file('valce.ini');
curl_setopt($curl, CURLOPT_URL, "https://valce.travail.gouv.fr/");
$fields = ['php_action' =>	"CONNEXION_do",
'identifiant' => $con['username'],
'motdepasse' => $con['password']];
curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
curl_exec($curl);

#################################################
# Loading ids for selected titre professionnel  #
#################################################

$search_fields = ['php_action' => 'JURYS_RECHERCHE',
				'departement' => '044',
				'region' => '-1',
				'mode' => 'validate'];
				
switch ($cursus) {
	case 'ARH':
		$search_fields['titreccs'] = 'tit_4049';
		break;
	case 'DL':
		$search_fields['titreccs'] = 'tit_4036';
		break;
	case 'CDI':
		$search_fields['titreccs'] = 'tit_4058';
		break;

	default:
		echo "Arretez de m'embeter";
		break;
}
curl_setopt($curl, CURLOPT_URL, "https://valce.travail.gouv.fr/index.php");
curl_setopt($curl, CURLOPT_POSTFIELDS, $search_fields);
$return = curl_exec($curl);

#################################################
# Couting pages                                 #
#################################################
$matches = [];
$number_of_pages = 1;
if (1 === preg_match('#(\d+) pages#', $return, $matches)) $number_of_pages = $matches[1];
#################################################
# Extracting jury ids from pages                #
#################################################
$ids = [];
for ($i=1; $i <= $number_of_pages; $i++) { 
	curl_setopt($curl, CURLOPT_URL, 'https://valce.travail.gouv.fr/index.php?php_action=JURYS_RECHERCHE&page='.$i);
	$return = curl_exec($curl);
	preg_match_all('#id_jury=(\d+)#', $return, $matches);
	$ids = array_merge($ids, $matches[1]);
}

#################################################
# Extracting data for each jury                 #
#################################################
$pattern = '$class="intituletable">%s.*?/td.*?/td.*?/td.*?<td.*?>(.*?)</td>$s';
$data = ['N.m de naissance', 'Pr.*?nom', 'Adresse personnelle', 'Code Postal', 'Commune', 'T.*?l.*?phone personnel', 'T.*?l.*?phone professionnel', 'M.*?l'];
foreach ($ids as $id) {
	$people = [];
	//goto https://valce.travail.gouv.fr/index.php?php_action=JURYS_CONSULT&id_jury=
	curl_setopt($curl, CURLOPT_URL, "https://valce.travail.gouv.fr/index.php?php_action=JURYS_CONSULT&id_jury=".$id);
	$return = curl_exec($curl);
	foreach ($data as $field) {
		preg_match(sprintf($pattern, $field), $return, $matches);
		$people[] = $matches[1];
	}
	if (!$people[7]) {
		// second mail is a sepcial special case. Indeeed
		preg_match_all(sprintf($pattern, $field), $return, $matches);
		// this are auto enclosed to avoid issues with Excel and phone numbers and be consistent with it
		$people[7] = $matches[1][1];		
	}
	fputcsv($csv_file, $people, ";");

	// break;
}
fclose($csv_file);
curl_close($curl);

#################################################
# Sending file to user                          #
#################################################

//Get file type and set it as Content Type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
header('Content-Type: ' . finfo_file($finfo, $path_csv));
finfo_close($finfo);

//Use Content-Disposition: attachment to specify the filename
header('Content-Disposition: attachment; filename='.basename($path_csv));

//No cache
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');

//Define file size
header('Content-Length: ' . filesize($path_csv));

ob_clean();
flush();
readfile($path_csv);
unlink($path_csv);
}