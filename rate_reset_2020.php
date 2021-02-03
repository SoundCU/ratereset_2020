#!/usr/bin/php
<?php

$DEBUG = false;
/*
Rate Reset Processing

Version: 2.0.1

Reformat of the code to allow maximum configurability with a simple json formatted file.

Notes on files:

  ## Dependencies:
       - configfile.json : Json formatted file which controls settings and data file definitions
       - docusign_infile : file name, path, and columns defeined in config file, source of member data

  ## Output
       - sym_postfile : name and data defined in config file
       - synergy xml index files, fields defined in config file, name matches corresponding pdf files destined for Synergy

*/

/*************  functions  and objects here **************/


// Parses standard formatted command line args and returns key value pairs
// rate_reset -x -y=10 --zzzzz=something

function parseArguments($argv)
{
    array_shift($argv);
    $out = array();
    foreach($argv as $arg)
    {
        if(substr($arg, 0, 2) == '--')
        {
            $eqPos = strpos($arg, '=');
            if($eqPos === false)
            {
                $key = substr($arg, 2);
                $out[$key] = isset($out[$key]) ? $out[$key] : true;
            }
            else
            {
                $key = substr($arg, 2, $eqPos - 2);
                $out[$key] = substr($arg, $eqPos + 1);
            }
        }
        else if(substr($arg, 0, 1) == '-')
        {
            if(substr($arg, 2, 1) == '=')
            {
                $key = substr($arg, 1, 1);
                $out[$key] = substr($arg, 3);
            }
            else
            {
                $chars = str_split(substr($arg, 1));
                foreach($chars as $char)
                {
                    $key = $char;
                    $out[$key] = isset($out[$key]) ? $out[$key] : true;
                }
            }
        }
        else
        {
            $out[] = $arg;
        }
    }
    return $out;
}


function build_syn_index($syn_index_data) {

  /*
     syn_index_data
       - FileRoom
       - DocName
       - Cabinet
       - DeleteFiles
       - Type
       - Institution
       - Indexes[]
         + Name => value
       - Pages[]
         + fname
  */

  $syn_idx =
'<?xml version="1.0"?>
<!--SymFormPDF Optical XML Filer-->
<FilingJob>
    <Batch SeqNum="1">
     <FileRoom>'.$syn_index_data['FileRoom'].'</FileRoom>
        <DeleteFiles>true</DeleteFiles>
        <Document SeqNum="1">
            <DocName>'.strtoupper($syn_index_data['DocName']).'</DocName>
            <Cabinet>'.strtoupper($syn_index_data['Cabinet']).'</Cabinet>
            <Type>'.strtoupper($syn_index_data['Type']).'</Type>
            <Institution>'.$syn_index_data['Institution'].'</Institution>
            <Indexes>
';

  foreach( $syn_index_data['Indexes'] as $name => $value) {
    $syn_idx.='                <Index Name="'.$name.'">'.$value.'</Index>'."\n";
  }

  $syn_idx.='            </Indexes>
            <Pages>
';


  foreach( $syn_index_data['Pages'] as $seq => $pdf_file ){
     $syn_idx.='                <Page SeqNum="'.($seq+1).'">'.$pdf_file.'.pdf</Page>'."\n";
  }

  $syn_idx.='            </Pages>
        </Document>
    </Batch>
</FilingJob>
';

  return $syn_idx;
}


function build_csv_str($loan_mod, $symFields) {
  $csv_str = "";

  foreach ($symFields as $fieldName) {
    if ($csv_str !== "") {
      $csv_str.=',';
    }
    $csv_str.= preg_replace('/[,]/', '', rtrim($loan_mod[$fieldName]," "));
  }
  $csv_str.="\n";

  return $csv_str;
}


function rrmdir($dir) {
  if (is_dir($dir)) {
    $objects = scandir($dir);
    foreach ($objects as $object) {
      if ($object != "." && $object != "..") {
        if (is_dir($dir."/".$object))
          rrmdir($dir."/".$object);
        else
          unlink($dir."/".$object);
      }
    }
    rmdir($dir);
  }
}

function purge_archive($base_dir) {
 $d = dir($base_dir.'archive');
 while (false !== ($entry = $d->read())) {
   if (is_dir($base_dir.'archive/'.$entry) && ($entry != '.') && ($entry != '..')) {
     if ((strtotime($entry)<strtotime("-1 week")) && strtotime($entry)!== false) {
       rrmdir($base_dir.'archive/'.$entry);
     }
   }
 }

 $d->close();
}

// sends all logs to stdout, let env handle output

function logger($message, $error = false) {
  $t_string = date("Y-m-d H:i:s ");

  if ($error == true) {
    $t_string.="ERROR: ";
  }
  $t_string.= $message."\n";

  echo $t_string;
}

function validateDataFileHeader($header, $req_fields) {

  foreach ($req_fields as $field) {
    if ( !in_array($field, $header) ) {
      return false;
    }
  }

  return true;
}


function filter_template_values( $filterPattern, $filterReplace, $config_value) {
  $ret_str = preg_filter($filterPattern, $filterReplace, $config_value);

  if ($ret_str !== null) {
    return $ret_str;
  }
  else {
    return $config_value;
  }
}

/***************  Program actually starts doing something here  ***************/

// gather and parse cmd line args - exit if no config file specified
$run_args = parseArguments($argv);
if ( $run_args['config'] !== "" ) {
  $cfg_file = $run_args['config'];
}
else {
  logger("No config file specified.", true);
  exit (1);
}

// read the config file, exit if not found
if ( file_exists($cfg_file) ) {
  $config_str = file_get_contents($cfg_file);
}
else {
  logger("No config file found.", true);
  exit (1);
}

// decode json format into an associative array (optional true flag in json_decode)
$config = json_decode($config_str, true);

// sets some vars based on config file
$base_dir = $config['basedir'];
if ( substr($base_dir, -1) != '/') {
  $base_dir.="/";
}
$base_dir.= $config['typedir'];
if ( substr($base_dir, -1) != '/') {
  $base_dir.="/";
}


$archive_dir = $config['basedir'];
if ( substr($archive_dir, -1) != '/') {
  $archive_dir.="/";
}
$archive_dir.='archive/'.date('Ymd').'/'.$config['typedir'];
if ( substr($archive_dir, -1) != '/') {
  $archive_dir.="/";
}
if (!is_dir($archive_dir)) {
  mkdir($archive_dir,0777,true);
}


// log start of script running
logger( "rate_reset started for ".$config['description'] );


// set and verify input data file exists, add file to archive_list[]
$inputfile = $base_dir.$config['docusign_infile']['filename'];
if ( !file_exists($inputfile) ) {
  logger("datafile $inputfile was not found.", true);
  exit (1);
}
$archive_list[] = $config['docusign_infile']['filename'];


// read input file as an array
$loan_recs = file($inputfile);


// transform $loan_recs into an associated array, $loan_data
$loan_data = array();
foreach ($loan_recs as $loan) {
  if (strpos($loan, 'Envelope Id') !== false) {
    $header = str_getcsv($loan, ",");
    if ( !validateDataFileHeader($header, $config['docusign_infile']['infile_fields']) ) {
      logger("invalid datafile; missing required fields", true);
      exit (1);
    }
  }
  else {
    if (is_array($header)) {
      $rec = str_getcsv($loan, ",");
      $loan_data[] = array_combine($header, $rec);
    }
    else {
      logger("no header defined in datafile", true);
      exit(1);
    }
  }
}


$syn_index_data = array ();
$symitar_csv_list = array();
foreach( $loan_data as $loan_mod ) {

  // setup pattern and replacement values for filtering template values
  foreach ( $config['docusign_infile']['infile_fields'] as $filterIdx => $filterRef ) {
    $filterPattern[$filterIdx]='/{{'.$filterRef.'}}/';
    $filterReplace[$filterIdx]=$loan_mod[$filterRef];
  }
  $filterIdx++;
  $filterPattern[$filterIdx]='/{{_today_}}/';
  $filterReplace[$filterIdx]=date("m/d/Y");


  // build csv line for symitar file
  $symFields = $config['sym_postfile']['postfile_fields'];
  $symitar_csv_list[] = build_csv_str($loan_mod, $symFields);

  // build syn_index_data for synergy filing

  $synSettings=$config['SynergyImportSettings'];

  $syn_index_data['FileRoom'] =  filter_template_values( $filterPattern, $filterReplace, $synSettings['file_room']);
  $syn_index_data['DocName'] =  filter_template_values( $filterPattern, $filterReplace, $synSettings['docname']);
  $syn_index_data['Cabinet'] =  filter_template_values( $filterPattern, $filterReplace, $synSettings['cabinet']);
  $syn_index_data['DeleteFiles'] =  filter_template_values( $filterPattern, $filterReplace, $synSettings['deleteFiles']);
  $syn_index_data['Type'] =  filter_template_values( $filterPattern, $filterReplace, $synSettings['type']);
  $syn_index_data['Institution'] =  filter_template_values( $filterPattern, $filterReplace, $synSettings['institution']);

  foreach ($synSettings['indexes'] as $index) {
    $idxName = $index['name'];
    $idxVal = filter_template_values( $filterPattern, $filterReplace, $index['value']);
    $syn_index_data['Indexes'][$idxName] = $idxVal;
  }

  $syn_index_data['Pages'][] = filter_template_values( $filterPattern, $filterReplace, $synSettings['file'] );

  file_put_contents($base_dir.$loan_mod['Envelope Id'].'.pdf.xml' ,build_syn_index($syn_index_data));

  if ($DEBUG === false) {
    $syn_import_host = $synSettings['import_host'];
    $syn_import_user = $synSettings['import_user'];
    $syn_import_pass = $synSettings['import_pass'];
    $syn_import_dir = $synSettings['import_dir'];

    $syn_import_conn=ftp_connect($syn_import_host);
    if (@ftp_login($syn_import_conn,$syn_import_user,$syn_import_pass)) {
      ftp_put($syn_import_conn,$syn_import_dir.$loan_mod['Envelope Id'].'.pdf',
              $base_dir.$loan_mod['Envelope Id'].'.pdf',
              FTP_BINARY);
      ftp_put($syn_import_conn,$syn_import_dir.$loan_mod['Envelope Id'].'.pdf.xml',
              $base_dir.$loan_mod['Envelope Id'].'.pdf.xml',
              FTP_BINARY);
    }
    ftp_close($syn_import_conn);
  }

  $archive_list[] = $loan_mod['Envelope Id'].'.pdf';
  $archive_list[] = $loan_mod['Envelope Id'].'.pdf.xml';

  unset($syn_index_data);
}

$symFile = $config['sym_postfile']['filename'];
if (strlen(implode('',$symitar_csv_list))>0) {
  file_put_contents($base_dir.$symFile,$symitar_csv_list);
}

foreach ($archive_list as $arch_file) {
  rename($base_dir.$arch_file,$archive_dir.$arch_file);
}

purge_archive($config['basedir']);

?>