<?php
use Phalcon\Mvc\Model;

class Convocatoriaspropuestasparametros extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con Convocatorias
        $this->belongsTo(
            'convocatoria',
            'Convocatorias',
            'id',
            [
                'foreignKey' => true
            ]
        );    
        
        //Se define la relación con 1 a N con Propuestasparametros
        $this->hasMany(
            'id',
            'Propuestasparametros',
            'convocatoriapropuestaparametro'
        ); 
        
        
    } 
    
}