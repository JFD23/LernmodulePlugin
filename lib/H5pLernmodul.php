<?php

class H5pLernmodul extends Lernmodul implements CustomLernmodul
{
    static protected function configure($config = array())
    {
        $config['has_and_belongs_to_many']['libs'] = array(
            'class_name' => 'H5PLib',
            'thru_table' => 'lernmodule_h5plib_module',
            'order_by' => 'ORDER BY name ASC'
        );
        parent::configure($config);
    }

    static public function detect($path)
    {
        return true;
    }



    public function afterInstall()
    {
        //initializing new libraries?
        $libs_data = array();
        foreach (scandir($this->getPath()) as $file) {
            if (is_dir($this->getPath() . "/" . $file) && preg_match("/^(.*)\-(\d+)\.(\d+)$/", $file, $matches)) {
                $libs_data[] = array(
                    'machineName' => $matches[1],
                    'majorVersion' => $matches[2],
                    'minorVersion' => $matches[3]
                );
            }
        }

        foreach ($libs_data as $lib_data) {

            $lib = H5PLib::findVersion($lib_data['machineName'], $lib_data['majorVersion'], $lib_data['minorVersion']);
            $lib_path = $this->getPath() . "/" . $lib_data['machineName'] . "-" . $lib_data['majorVersion'] . "." . $lib_data['minorVersion'];
            $lib_json = json_decode(file_get_contents($lib_path . "/library.json"), true);
            if (!$lib && $lib_json) {

                $lib = new H5PLib();
                $lib['name'] = $lib_data['machineName'];
                $lib['major_version'] = $lib_data['majorVersion'];
                $lib['minor_version'] = $lib_data['minorVersion'];
                $lib['patch_version'] = $lib_json['patchVersion'];
                $lib['allowed'] = LernmodulePlugin::mayEditSandbox() ? 1 : 0;
                $lib['runnable'] = $lib_json['runnable'] ? 1 : 0;
                $lib->store();

                if (file_exists($lib_path) && !file_exists($lib->getPath())) {
                    rename($lib_path, $lib->getPath());
                }
            }
            if (file_exists($lib_path)) {
                rmdirr($lib_path);
            }

            $statement = DBManager::get()->prepare("
                INSERT IGNORE INTO lernmodule_h5plib_module
                SET module_id = :module_id,
                    lib_id = :lib_id
            ");
            $statement->execute(array(
                'module_id' => $this->getId(),
                'lib_id' => $lib->getId()
            ));
        }
    }

    public function getEditTemplate() {}

    public function getViewerTemplate($attempt, $game_attendance = null)
    {
        $actions = new ActionsWidget();
        $actions->addLink(
            _("Vollbild"),
            "#",
            Icon::create("play", "clickable"),
            array('onClick' => "STUDIP.Lernmodule.requestFullscreen(); return false;")
        );
        Sidebar::Get()->addWidget($actions);

        if (!$this->isAllowed()) {
            $libs = array();
            foreach (H5PLib::findMany($this->findUnallowedLibraries()) as $lib) {
                $libs[] = $lib['name']." ".$lib['major_version'].".".$lib['minor_version'];
            }
            PageLayout::postInfo(_("Dieses H5P-Modul benutzt Bibliotheken, die noch nicht freigegeben sind."), $libs);
            return "";
        } else {
            $templatefactory = new Flexi_TemplateFactory(__DIR__ . "/../views");
            $template = $templatefactory->open("h5p/view.php");
            $template->set_attribute("module", $this);
            return $template;
        }
    }

    public function getEvaluationTemplate($course_id)
    {
        return null;
    }

    public function evaluateAttempt($attempt)
    {
        return null;
    }

    public function getLibs()
    {
        $json = json_decode(file_get_contents($this->getPath() . "/h5p.json"), true);
        $libs = array();
        $lib_ids = array();
        if ($json) {
            foreach ($json['preloadedDependencies'] as $lib_data) {
                $lib = H5PLib::findVersion($lib_data['machineName'], $lib_data['majorVersion'], $lib_data['minorVersion']);
                if ($lib && !in_array($lib->getId(), $lib_ids)) {
                    $lib_ids[] = $lib->getId();
                    $libs[] = $lib;
                    foreach ($lib->getSubLibs() as $sublib) {
                        if (!in_array($sublib->getId(), $lib_ids)) {
                            $lib_ids[] = $sublib->getId();
                            array_unshift($libs, $sublib);
                        }
                    }
                }
            }
        }

        //We need to sort these libs in order of their dependencies: (why don't they just rely on onLoad events?)
        //We need a topological sort algorithm for that, because the set of libraries is a non well ordered list.
        $lib_ids = array_map(function ($l) { return $l->getId(); }, $libs);
        $edges = array();
        foreach ($libs as $lib) {
            foreach ($lib->getSubLibs() as $sublib) {
                $edges[] = array(
                    $sublib->getId(),
                    $lib->getId()
                );
            }
        }
        $lib_ids = $this->topologicalSort($lib_ids, $edges);
        if ($lib_ids === false) {
            throw new Exception("Could not sort dependencies of H5P module.");
        }
        $libs_sorted = array();
        foreach ($lib_ids as $lib_id) {
            foreach ($libs as $i => $lib) {
                if ($lib->getId() == $lib_id) {
                    $libs_sorted[] = $lib;
                    unset($libs[$i]);
                    break;
                }
            }
        }

        return $libs_sorted;
    }

    /**
     * Processes a topological sort
     * @param $nodeids : array of ids
     * @param array $edges : array of arrays like array(node1_id, node2_id)
     * @return array|bool : either the sorted array of ids or false if the graph has cycles.
     */
    protected function topologicalSort($nodeids, $edges) {
        $L = $S = $nodes = array();
        foreach($nodeids as $id) {
            $nodes[$id] = array(
                'in'=>array(),
                'out'=>array()
            );
            foreach($edges as $e) {
                if ($id == $e[0]) {
                    $nodes[$id]['out'][] = $e[1];
                }
                if ($id == $e[1]) {
                    $nodes[$id]['in'][] = $e[0];
                }
            }
        }
        foreach ($nodes as $id => $n) {
            if (empty($n['in'])) {
                $S[] = $id;
            }
        }
        while (!empty($S)) {
            $L[] = $id = (string) array_shift($S);
            foreach($nodes[$id]['out'] as $m) {
                $nodes[$m]['in'] = array_diff($nodes[$m]['in'], array($id));
                if (empty($nodes[$m]['in'])) {
                    $S[] = $m;
                }
            }
            $nodes[$id]['out'] = array();
        }
        foreach($nodes as $n) {
            if (!empty($n['in']) or !empty($n['out'])) {
                return false; // not sortable as graph is cyclic
            }
        }
        return $L;
    }

    /**
     * @param $css_or_jss : "css" or "js"
     * @return array
     * @throws Exception
     */
    protected function getFiles($css_or_jss)
    {
        $files = array();
        foreach ($this->getLibs() as $lib) {
            $files = array_merge($files, $lib->getFiles($css_or_jss));
        }
        return $files;
    }

    public function getJSFiles()
    {
        return $this->getFiles("js");
    }

    public function getCSSFiles()
    {
        return $this->getFiles("css");
    }

    public function getH5pLibURL()
    {
        if (Config::get()->LERNMODUL_DATA_URL) {
            return Config::get()->LERNMODUL_DATA_URL."/h5plibs";
        } else {
            return $GLOBALS['ABSOLUTE_URI_STUDIP'] . "plugins_packages/RasmusFuhse/LernmodulePluginData/h5plibs";
        }
    }

    public function isAllowed() {
        return count($this->findUnallowedLibraries()) == 0;
    }

    public function findUnallowedLibraries() {
        $statement = DBManager::get()->prepare("
            SELECT lernmodule_h5plib_module.lib_id
            FROM lernmodule_h5plib_module
                INNER JOIN lernmodule_h5plibs ON (lernmodule_h5plibs.lib_id = lernmodule_h5plib_module.lib_id)
            WHERE lernmodule_h5plibs.allowed = '0'
                AND lernmodule_h5plib_module.module_id = :module_id
        ");
        $statement->execute(array('module_id' => $this->getId()));
        $disallowed = $statement->fetchAll(PDO::FETCH_COLUMN);
        return $disallowed;
    }
}