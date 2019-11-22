<?php


if (!defined('_PS_VERSION_')) {
    exit;
}

class jzbywordpressfeed extends Module {
    
    var $hooks = array("displayLeftColumn", "displayRightColum", "displayHome");
    var $config = array();
    
    public function __construct() {
        $this->name = "jzbywordpressfeed";
        $this->tab = "pricing_promotion";
        $this->version = "1.0";
        $this->author = "Jacek Zbysinski";
        $this->need_instance = 1;
        $this->ps_versions_compliancy = [
            'min' => '1.6',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;
        
        parent::__construct();

        $this->displayName = $this->l('Wordpress Feed for PrestaShop');
        $this->description = $this->l('Module displays WordPress posts in your PrestaShop');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }
    
    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }
        if (!parent::install() ||
            !$this->registerHook('leftColumn') ||
            !$this->registerHook('rightColumn') ||
            !$this->registerHook('home') || 
            !Configuration::updateValue('JZBYWORDPRESSFEED_CACHETTL', 3600)
        ) {
            return false;
        }
        return parent::install();
    }
    
    public function uninstall()
    {
        if (!parent::uninstall() 
            //||            !Configuration::deleteByName('MYMODULE_NAME')
        ) {
            return false;
        }

        return true;
    }
    
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {
            $myModuleName = strval(Tools::getValue('JZBY_WP_FEED_URP'));

            if (
                !$myModuleName ||
                empty($myModuleName) ||
                !Validate::isGenericName($myModuleName)
            ) {
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            } else {
                Configuration::updateValue('JZBY_WP_FEED_URP', $myModuleName);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output.$this->displayForm();
    }
    
    public function displayForm() {
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');
        
        $formFields[0]['form'] = [
            'legend' => [
                'title' => $this->l('Wordpress Feed settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Wordpress RSS Feed URL'),
                    'name' => 'JZBY_WP_FEED_URP',
                    'size' => 20,
                    'required' => true
                ]
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Load current value
        $helper->fields_value['JZBY_WP_FEED_URP'] = Configuration::get('JZBY_WP_FEED_URP');

        return $helper->generateForm($formFields);
    }
    
    protected function curlGet($url, $params = NULL) {
        $ps = '';
        
        if ($params) {
            foreach ($params as $key => $value)
                $ps .= $key.'='. urlencode($value) . '&';
        }
        
        //echo "url = ".$url . '?' . trim($ps, '&');
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url . '?' . trim($ps, '&')); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
        $output = curl_exec($ch); 
        curl_close($ch);
        return $output;
    } 
    
    protected function prepareImage($url, $id, $hookName) {
        $fn = _PS_MODULE_DIR_."jzbywordpressfeed/images/".$hookName."_".$id.".".pathinfo($url, PATHINFO_EXTENSION);
        $furl = Tools::getHttpHost(true).__PS_BASE_URI__._MODULE_DIR_."jzbywordpressfeed/images/".$hookName."_".$id.".".pathinfo($url, PATHINFO_EXTENSION);
        $options = array(
            CURLOPT_FILE    => fopen($fn, "w+"),
            CURLOPT_TIMEOUT =>  28800,
            CURLOPT_URL     => $url,
        );
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) return $furl; else return false;
    }
    
    protected function getPosts($hookName, $config) {
        $postListJSON = $this->curlGet($config['url'] . '/wp-json/wp/v2/posts', array(
            'per_page' => $config['count'],
            'order' => $config['order'],
            'orderby' => $config['orderby'],
            'tags' => $config['tags']
        ));
        $postList = json_decode($postListJSON);
        
        //print_r($postList);
        
        $resultPosts = array();
        
        foreach ($postList as &$post) {
            if ($post->featured_media) {
                $imgJSON =  $this->curlGet($config['url'] . '/wp-json/wp/v2/media/' . $post->featured_media);
                if (!$imgJSON) return false;
                $img = json_decode($imgJSON);
                $resultPost['img_src'] = $this->prepareImage($img->source_url, $post->id, $hookName);
                if (!$resultPost['img_src']) return false;
                
            }
            $resultPost['title'] = $post->title->rendered;
            $resultPost['excerpt'] = $post->excerpt->rendered;
            $resultPost['url'] = $post->link;
            $resultPosts[] = $resultPost;
        }
        
        return $resultPosts;
    }
    
    protected function loadConfig() {
        $this->config = unserialize(Configuration::get('JZBYWORDPRESSFEED_CONFIG'));
        $this->config['hooks']['leftColumn']['url'] = 'https://blog.kamami.pl';
        $this->config['hooks']['leftColumn']['tags'] = '';
        $this->config['hooks']['leftColumn']['count'] = 7;
        $this->config['hooks']['leftColumn']['orderby'] = 'date';
        $this->config['hooks']['leftColumn']['order'] = 'desc';
    }
    
    protected function getContentForHook($hookName) {
        
        
        $cacheKey = 'jzbywordpressfeed_'.$hookName;
        
        if (true) {    
            $this->loadConfig();
            if (array_key_exists($hookName, $this->config['hooks'])) {
                $posts = $this->getPosts($hookName, $this->config['hooks'][$hookName]);
                return print_r($posts, true);
                return $content;
            }
            else return "BLAD!!!";
        }
    } 
    
    public function hookDisplayLeftColumn($params)
    {
        return $this->getContentForHook("leftColumn");
    }

    public function hookDisplayRightColumn($params)
    {
        return $this->getContentForHook("rightColumn");
    }
    
    public function hookDisplayHome($params) {
        return $this->getContentForHook("home");
    }
}