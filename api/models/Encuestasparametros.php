<?php
use Phalcon\Mvc\Model;

class Encuestasparametros extends Model
{
    public $id;
    
    public function initialize()
    {
       //Se define relacion de N a 1 con Encuestas
        $this->belongsTo(
            'encuesta',
            'Encuestas',
            'id',
            [
                'foreignKey' => true
            ]
        ); 
    }  
    
}