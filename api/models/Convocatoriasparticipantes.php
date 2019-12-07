<?php
use Phalcon\Mvc\Model;

class Convocatoriasparticipantes extends Model
{
    public $id;

    public function initialize() {

        //Se define relacion de N a 1 con tipos participantes
        $this->belongsTo(
                'tipo_participante',
                'Tiposparticipantes',
                'id'
        );

        //14 oct 2019->Cesar Britto
        /* Se define la relación con N a 1 con Convocatorias, con el fin de
         * obtener la Convocatoria
         */
        $this->belongsTo(
                'convocatoria','Convocatorias','id'
        );

    }




}
