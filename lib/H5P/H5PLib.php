<?php

class H5PLib extends SimpleORMap
{
    static protected function configure($config = array())
    {
        $config['db_table'] = 'lernmodule_h5plibs';
        parent::configure($config);
    }

    static public function findVersion($name, $major_version, $minor_version)
    {
        return self::findOneBySQL("name = :name AND major_version = :major AND minor_version = :minor", array(
            'name' => $name,
            'major' => $major_version,
            'minor' => $minor_version
        ));
    }

    public function getPath()
    {
        if (Config::get()->LERNMODUL_DATA_PATH) {
            $datafolder = Config::get()->LERNMODUL_DATA_PATH;
        } else {
            $datafolder = realpath(__DIR__."/../../..")."/LernmodulePluginData";
            if (!file_exists($datafolder)) {
                $success = mkdir($datafolder);
                if (!$success) {
                    throw new Exception("Konnte Verzeichnis LernmodulePluginData nicht anlegen.");
                }
            }
        }
        $datafolder .= "/h5plibs";
        if (!file_exists($datafolder)) {
            $success = mkdir($datafolder);
            if (!$success) {
                throw new Exception("Konnte Verzeichnis LernmodulePluginData/h5plibs nicht anlegen.");
            }
        }
        return $datafolder."/".$this['name']."-".$this['major_version'].".".$this['minor_version'];
    }

    public function countUsage()
    {
        $statement = DBManager::get()->prepare("
            SELECT COUNT(*) 
            FROM lernmodule_h5plib_module
            WHERE lib_id = ?
        ");
        $statement->execute(array($this->getId()));
        return $statement->fetch(PDO::FETCH_COLUMN, 0);
    }

    public function getSubLibs($not_in_ids = array())
    {
        $library_json = $this->getPath()."/library.json";
        $sublibs = array();
        if (file_exists($library_json)) {
            $json = json_decode(file_get_contents($library_json), true);
            foreach ((array) $json['preloadedDependencies'] as $dependency) {
                $sublib = H5PLib::findVersion(
                    $dependency['machineName'],
                    $dependency['majorVersion'],
                    $dependency['minorVersion']
                );
                if ($sublib && !in_array($sublib->getId(), $not_in_ids)) {
                    array_unshift($sublibs, $sublib);
                    $not_in_ids[] = $sublib->getId();
                    foreach ($sublib->getSubLibs($not_in_ids) as $subsublib) {
                        array_unshift($sublibs, $subsublib);
                        $not_in_ids[] = $subsublib->getId();
                    }
                }
            }
            $semantics_json = $this->getPath()."/semantics.json";


            if ($json['runnable'] && file_exists($semantics_json)) {

                $semantics = json_decode(file_get_contents($semantics_json), true);
                $more_libs = $this->fetchSemanticsLibraries($semantics, $not_in_ids);
                foreach ($more_libs as $lib) {
                    $sublibs[] = $lib;
                    $not_in_ids[] = $lib->getId();
                }
            }

        }

        return $sublibs;
    }


    protected function fetchSemanticsLibraries($fields, $not_in_ids = array()) {
        $libs = array();

        foreach ($fields as $semantic) {
            if ($semantic['type'] === "library") {

                foreach ($semantic['options'] as $lib_name) {
                    preg_match("/^(.+)\s+(\d+)\.(\d+)$/", $lib_name, $matches);
                    $sublib = H5PLib::findVersion(
                        $matches[1],
                        $matches[2],
                        $matches[3]
                    );
                    if ($sublib && !in_array($sublib->getId(), $not_in_ids)) {
                        //array_unshift($libs, $sublib);
                        $libs[] = $sublib;
                        $not_in_ids[] = $sublib->getId();
                        foreach ($sublib->getSubLibs($not_in_ids) as $subsublib) {
                            //array_unshift($libs, $subsublib);
                            $libs[] = $subsublib;
                            $not_in_ids[] = $subsublib->getId();
                        }
                    }
                }
            }
            if (isset($semantic['fields']) && count($semantic['fields'])) {
                foreach ($this->fetchSemanticsLibraries($semantic['fields'], $not_in_ids) as $lib) {
                    //array_unshift($libs, $lib);
                    $libs[] = $lib;
                    $not_in_ids[] = $lib->getId();
                }
            } elseif(isset($semantic['field']) && count($semantic['field'])) {
                foreach ($this->fetchSemanticsLibraries(array($semantic['field']), $not_in_ids) as $lib) {
                    //array_unshift($libs, $lib);
                    $libs[] = $lib;
                    $not_in_ids[] = $lib->getId();
                }
            }
        }
        return $libs;
    }

    public function getFiles($css_or_jss)
    {
        $library_json = $this->getPath()."/library.json";
        $files = array();
        if (file_exists($library_json)) {
            $dir_name = $this['name']."-".$this['major_version'].".".$this['minor_version'];
            $library_json = json_decode(file_get_contents($library_json), true);
            foreach ((array) $library_json[$css_or_jss === "js" ? 'preloadedJs' : "preloadedCss"] as $file) {
                $files[] = $dir_name."/".$file['path'];
            }
        }
        return $files;
    }

    public function getLibraryData() {
        $library_json = $this->getPath()."/library.json";
        return json_decode(file_get_contents($library_json), true);
    }
}