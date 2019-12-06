<?php
use Phalcon\Mvc\Model;

class Convocatoriasdocumentos extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con tipos_eventos
        $this->belongsTo(
                'requisito', 'Requisitos', 'id'
        );
        
        //Se define la relación con 1 a N con Propuestasdocumentos
        $this->hasMany(
            'id',
            'Propuestasdocumentos',
            'convocatoriadocumento'
        ); 
        
        //Se define la relación con 1 a N con Propuestaslinks
        $this->hasMany(
            'id',
            'Propuestaslinks',
            'convocatoriadocumento'
        ); 
    }
}