<?php
use Phalcon\Mvc\Model;

class Categoriajurado extends Model
{
    public $id;

    public function initialize()
    {

    }

     public function validation(){
      
       return true;
     }


}
