<?php
use Phalcon\Mvc\Model;

class Orientacionessexuales extends Model
{
    public $id;
    
    public function initialize()
    {        
        //Se define la relación con 1 a N con participantes
        $this->hasMany(
            'id',
            'Participantes',
            'orientacion_sexual'
        );  
        
        
    }
}