-- Creación del esquema de auditoria

CREATE SCHEMA auditoria AUTHORIZATION postgres;

-- Drop table

-- DROP TABLE auditoria.logged_actions;

CREATE TABLE auditoria.logged_actions (
	schema_name text NOT NULL,
	table_name text NOT NULL,
	user_name text NULL,
	action_tstamp timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
	"action" text NOT NULL,
	id_data int8 NULL,
	old_data text NULL,
	new_data text NULL,
	query text NULL,
	CONSTRAINT logged_actions_action_check CHECK ((action = ANY (ARRAY['I'::text, 'D'::text, 'U'::text])))
);
CREATE INDEX logged_actions_action_idx ON auditoria.logged_actions USING btree (action);
CREATE INDEX logged_actions_action_tstamp_idx ON auditoria.logged_actions USING btree (action_tstamp);
CREATE INDEX logged_actions_schema_table_idx ON auditoria.logged_actions USING btree ((((schema_name || '.'::text) || table_name)));

-- Permissions

ALTER TABLE auditoria.logged_actions OWNER TO postgres;
GRANT ALL ON TABLE auditoria.logged_actions TO postgres;
GRANT SELECT ON TABLE auditoria.logged_actions TO public;

-- funcion auditoria

CREATE OR REPLACE FUNCTION auditoria.func_logged_actions()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_old_data TEXT;
    v_new_data TEXT;
begin
	   IF (TG_OP = 'UPDATE') THEN
        v_old_data := ROW(OLD.*);
        v_new_data := ROW(NEW.*);
        INSERT INTO auditoria.logged_actions (schema_name,table_name,user_name,action,id_data,old_data,new_data,query) 
        VALUES (TG_TABLE_SCHEMA::TEXT,TG_TABLE_NAME::TEXT,session_user::TEXT,substring(TG_OP,1,1),OLD.id, v_old_data,v_new_data, current_query());
        RETURN NEW;
    ELSIF (TG_OP = 'DELETE') THEN
        v_old_data := ROW(OLD.*);
        INSERT INTO auditoria.logged_actions (schema_name,table_name,user_name,action,old_data,query)
        VALUES (TG_TABLE_SCHEMA::TEXT,TG_TABLE_NAME::TEXT,session_user::TEXT,substring(TG_OP,1,1),v_old_data, current_query());
        RETURN OLD;
    ELSIF (TG_OP = 'INSERT') THEN
        v_new_data := ROW(NEW.*);
        INSERT INTO auditoria.logged_actions (schema_name,table_name,user_name,action,new_data,query)
        VALUES (TG_TABLE_SCHEMA::TEXT,TG_TABLE_NAME::TEXT,session_user::TEXT,substring(TG_OP,1,1),v_new_data, current_query());
        RETURN NEW;
    ELSE
        RAISE WARNING '[AUDITORIA.LOGGED_ACTIONS] - Other action occurred: %, at %',TG_OP,now();
        RETURN NULL;
    END IF;

 EXCEPTION
    WHEN data_exception THEN
        RAISE WARNING '[AUDITORIA.LOGGED_ACTIONS] - UDF ERROR [DATA EXCEPTION] - SQLSTATE: %, SQLERRM: %',SQLSTATE,SQLERRM;
        RETURN NULL;
    WHEN unique_violation THEN
        RAISE WARNING '[AUDITORIA.LOGGED_ACTIONS] - UDF ERROR [UNIQUE] - SQLSTATE: %, SQLERRM: %',SQLSTATE,SQLERRM;
        RETURN NULL;
    WHEN OTHERS THEN
        RAISE WARNING '[AUDITORIA.LOGGED_ACTIONS] - UDF ERROR [OTHER] - SQLSTATE: %, SQLERRM: %',SQLSTATE,SQLERRM;
        RETURN NULL;
END;
$function$
;

-- Creación de los disparadores de jurados

create trigger trg_logged_actions_convocatoriasrondas after
insert or delete or update
on public.convocatoriasrondas for each row execute procedure auditoria.func_logged_actions();

create trigger trg_logged_actions_juradosnotificaciones after
insert or delete or update
on public.juradosnotificaciones for each row execute procedure auditoria.func_logged_actions();

create trigger trg_logged_actions_gruposevaluadores after
insert or delete or update
on public.gruposevaluadores for each row execute procedure auditoria.func_logged_actions();

create trigger trg_logged_actions_evaluadores after
insert or delete or update
on public.evaluadores for each row execute procedure auditoria.func_logged_actions();

create trigger trg_logged_actions_evaluacionpropuestas after
insert or delete or update
on public.evaluacionpropuestas for each row execute procedure auditoria.func_logged_actions();

create trigger trg_logged_actions_evaluacioncriterios after
insert or delete or update
on public.evaluacioncriterios for each row execute procedure auditoria.func_logged_actions();

-- creación de los disparadores de convocatorias
create trigger trg_logged_convocatorias after
insert
    or
delete
    or
update
    on
    public.convocatorias for each row execute procedure auditoria.func_logged_actions();
    
create trigger trg_logged_convocatoriasrecursos after
insert
    or
delete
    or
update
    on
    public.convocatoriasrecursos for each row execute procedure auditoria.func_logged_actions();
    
create trigger trg_logged_convocatoriasparticipantes after
insert
    or
delete
    or
update
    on
    public.convocatoriasparticipantes for each row execute procedure auditoria.func_logged_actions();
    
create trigger trg_logged_convocatoriascronogramas after
insert
    or
delete
    or
update
    on
    public.convocatoriascronogramas for each row execute procedure auditoria.func_logged_actions();
    
create trigger trg_logged_convocatoriasdocumentos after
insert
    or
delete
    or
update
    on
    public.convocatoriasdocumentos for each row execute procedure auditoria.func_logged_actions();            
    
    
