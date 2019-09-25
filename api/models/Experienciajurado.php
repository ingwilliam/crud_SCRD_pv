<?php
use Phalcon\Mvc\Model;

class Experienciajurado extends Model
{
    public $id;

    public function initialize()
    {
      //belongsTo	Defines a n-1 relationship
      $this->belongsTo(
          'propuesta',
          'Propuestas',
          'id'
      );
      
    }

     public function validation(){
       /*

       if ($this->area_conocimiento == "") {
         $this->area_conocimiento = null;
       }


       if ($this->nucleo_basico == "") {
         $this->nucleo_basico = null;
       }
       */
       return true;
     }


}
