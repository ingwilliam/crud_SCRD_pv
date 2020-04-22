<?php
use Phalcon\Mvc\Model;

class Encuestas extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Encuestasparametros
        $this->hasMany(
            'id',
            'Encuestasparametros',
            'encuesta'
        ); 
        
        
       //Se define relacion de N a 1 con Programas
        $this->belongsTo(
            'programa',
            'Programas',
            'id',
            [
                'foreignKey' => true
            ]
        ); 
    }  
    
}