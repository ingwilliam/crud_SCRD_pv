<?php

require_once ('../atom/cmis/cmis_repository_wrapper.php');
require_once ('../atom/cmis/cmis_service.php');

/**
 * Clase para gestionar los 
 * documentos en alfresco
 *
 * @author William Barbosa
 */
class ChemistryPV {

    private $client;

    function __construct($url, $username, $password) {
        $this->client = new CMISService($url, $username, $password);
    }

    function newFolder($repository, $new_folder) {
        try {
            $myfolder = $this->client->getObjectByPath($repository);
            $this->client->createFolder($myfolder->id, $new_folder);            
            echo "ok";
        } catch (CmisObjectNotFoundException $e) {
            return "Error 2: ".$e->getCode();
        } catch (CmisRuntimeException $e) {
            return "Error 1: ".$e->getCode();
        } catch (Exception $ex) {
            return "Error: método";
        }
    }

    function newFile($repository, $name_file, $content, $content_type) {
        try {
            $myfolder = $this->client->getObjectByPath($repository);
            return $this->client->createDocument($myfolder->id, $name_file, array(), $content, $content_type)->id;            
        } catch (CmisObjectNotFoundException $e) {
            return "Error 2: ".$e->getCode();
        } catch (CmisRuntimeException $e) {
            return "Error 1: ".$e->getCode();
        } catch (Exception $ex) {
            return "Error: método";
        }
    }

    function download($object_id) {
        try {
            $props = $this->client->getProperties($object_id);
            $file_name = $props->properties['cmis:name'];
            $file_size = $props->properties['cmis:contentStreamLength'];
            $mime_type = $props->properties['cmis:contentStreamMimeType'];
            header('Content-type: ' . $mime_type);
            header('Content-Disposition: attachment; filename="' . $file_name . '"');
            header("Content-length: {$file_size}");
            echo $this->client->getContentStream($object_id);            
        } catch (CmisObjectNotFoundException $e) {
            return "Error 2: ".$e->getCode();
        } catch (CmisRuntimeException $e) {
            return "Error 1: ".$e->getCode();
        } catch (Exception $ex) {
            return "Error: método";
        }
    }

    function search($folder, $name=null ) {
        $query_conditions = array();        
        try {
            if ($folder) {
                $f = $this->client->getObjectByPath($folder);
                array_push($query_conditions, "IN_FOLDER('{$f->id}')");
            }
            if ($name) {
                array_push($query_conditions, "cmis:name like '%{$name}%'");
            }
            
            if (sizeof($query_conditions)) {
                //$query = "SELECT d.*, o.* FROM cmis:document AS d JOIN cm:titled AS o ON d.cmis:objectId = o.cmis:objectId WHERE " . join(" AND ", $query_conditions) . "ORDER BY d.cmis:name";
                $query = "SELECT * FROM cmis:document WHERE " . join(" AND ", $query_conditions). " ORDER BY cmis:name";
                return $this->client->query($query);
            }
        } catch (CmisObjectNotFoundException $e) {
            return "Error 2: ".$e->getCode();
        } catch (CmisRuntimeException $e) {
            return "Error 1: ".$e->getCode();
        } catch (Exception $ex) {
            return "Error: método";
        }       
    }

}