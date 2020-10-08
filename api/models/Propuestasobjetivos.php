<?php
use Phalcon\Mvc\Model;

class Propuestasobjetivos extends Model
{
    public $id;
    
    public function initialize()
    {                
        //Se define relacion de N a 1 con Propuestas
        $this->belongsTo(
            'propuesta',
            'Propuestas',
            'id'
        );
        
        //Se define la relación con 1 a N con Departamentos
        $this->hasMany(
            'id',
            'Propuestasactividades',
            'propuestaobjetivo'
        );   
    }    
}