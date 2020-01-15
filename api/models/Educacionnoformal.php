<?php
use Phalcon\Mvc\Model;

class Educacionnoformal extends Model
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
      
      //Se define relacion de N a 1 con Ciudades
      $this->belongsTo(
          'ciudad',
          'Ciudades',
          'id',
          [
              'alias' => "Ciudad"
          ]
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
