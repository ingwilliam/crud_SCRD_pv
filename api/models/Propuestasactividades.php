<?php
use Phalcon\Mvc\Model;

class Propuestasactividades extends Model
{
    public $id;
    
    public function initialize()
    {                
        //Se define relacion de N a 1 con Paises
        $this->belongsTo(
            'propuestaobjetivo',
            'Propuestasobjetivos',
            'id'
        );
        
        //Se define la relación con 1 a N con Propuestascronogramas
        $this->hasMany(
            'id',
            'Propuestascronogramas',
            'propuestaactividad'
        );
        
        //Se define la relación con 1 a N con Propuestaspresupuestos
        $this->hasMany(
            'id',
            'Propuestaspresupuestos',
            'propuestaactividad'
        ); 
        
    }    
}