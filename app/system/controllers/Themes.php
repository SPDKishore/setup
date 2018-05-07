<?php namespace System\Controllers;

use Admin\Traits\WidgetMaker;
use AdminAuth;
use AdminMenu;
use Exception;
use Main\Classes\ThemeManager;
use Request;
use System\Models\Themes_model;
use System\Traits\ConfigMaker;
use SystemException;
use Template;

class Themes extends \Admin\Classes\AdminController
{
    use WidgetMaker;
    use ConfigMaker;

    public $implement = [
        'Admin\Actions\ListController',
    ];

    public $listConfig = [
        'list' => [
            'model'        => 'System\Models\Themes_model',
            'title'        => 'lang:system::themes.text_title',
            'emptyMessage' => 'lang:system::themes.text_empty',
            'defaultSort'  => ['date_added', 'DESC'],
            'configFile'   => 'themes_model',
        ],
    ];

    public $formConfig = [
        'name'       => 'lang:system::themes.text_form_name',
        'model'      => 'System\Models\Themes_model',
        'create'     => [
            'title'         => 'lang:admin::default.form.create_title',
            'redirect'      => 'themes/edit/{code}',
            'redirectClose' => 'themes',
        ],
        'edit'       => [
            'title'         => 'lang:admin::default.form.edit_title',
            'redirect'      => 'themes/edit/{code}',
            'redirectClose' => 'themes',
        ],
        'delete'     => [
            'redirect' => 'themes',
        ],
        'configFile' => 'themes_model',
    ];

    /**
     * @var \Admin\Widgets\Form
     */
    public $formWidget;

    /**
     * @var \Admin\Widgets\Toolbar
     */
    public $toolbarWidget;

    public function __construct()
    {
        parent::__construct();

        Themes_model::syncAll();

        AdminMenu::setContext('themes', 'design');
    }

    public function edit($context, $themeCode = null)
    {
        try {
            $pageTitle = lang('system::themes.text_edit_title');
            Template::setTitle($pageTitle);
            Template::setHeading($pageTitle);

            Template::setButton(lang('system::themes.button_source'), [
                'class' => 'btn btn-default',
                'href'  => admin_url('themes/source/'.$themeCode),
            ]);

            $model = $this->formFindModelObject($themeCode);
            $this->initFormWidget($model, $context);
        } catch (Exception $ex) {
            $this->handleError($ex);
        }
    }

    public function source($context, $themeCode = null)
    {
        try {
            $pageTitle = lang('system::themes.text_edit_title');
            Template::setTitle($pageTitle);
            Template::setHeading($pageTitle);

            Template::setButton(lang('system::themes.text_customize'), [
                'class' => 'btn btn-default',
                'href'  => admin_url('themes/edit/'.$themeCode),
            ]);

            $model = $this->formFindModelObject($themeCode);
            $this->initFormWidget($model, $context);
        } catch (Exception $ex) {
            $this->handleError($ex);
        }
    }

    public function upload($context)
    {
        $pageTitle = lang('system::themes.text_add_title');
        Template::setTitle($pageTitle);
        Template::setHeading($pageTitle);

        Template::setButton(lang('admin::default.button_icon_back'), ['class' => 'btn btn-default', 'href' => admin_url('themes')]);
        Template::setButton(lang('system::themes.button_browse'), ['class' => 'btn btn-default pull-right', 'href' => admin_url('updates/browse/themes')]);
    }

    public function delete($context, $themeCode = null)
    {
        try {
            $pageTitle = lang('system::themes.text_delete_title');
            Template::setTitle($pageTitle);
            Template::setHeading($pageTitle);

            $themeManager = ThemeManager::instance();
            $themeClass = $themeManager->findTheme($themeCode);
            $model = Themes_model::whereCode($themeCode)->first();
            $activeThemeCode = params()->get('default_themes.main');

            // Theme must be disabled before it can be deleted
            if ($model AND $model->code == $activeThemeCode) {
                flash()->warning(sprintf(
                    lang('admin::default.alert_error_nothing'),
                    lang('admin::default.text_deleted').lang('system::themes.text_theme_is_active')
                ));

                return $this->redirectBack();
            }

            // Theme not found in filesystem
            // so delete from database
            if (!$themeClass) {
                Themes_model::deleteTheme($themeCode, TRUE);
                flash()->success(sprintf(lang('admin::default.alert_success'), "Theme deleted "));

                return $this->redirectBack();
            }

            // Lets display a delete confirmation screen
            // with list of files to be deleted
            $this->vars['themeModel'] = $model;
            $this->vars['themeClass'] = $themeClass;
            $this->vars['themeName'] = $themeClass->name;
            $this->vars['themeData'] = $model->data;
            $this->vars['filesToDelete'] = array_collapse($themeManager->listFiles($themeCode));
        } catch (Exception $ex) {
            $this->handleError($ex);
        }
    }

    public function index_onSetDefault()
    {
        if (!$this->getUser()->hasPermission('Site.Themes.Manage', TRUE))
            return $this->redirectBack();

        $themeName = post('code');
        if ($theme = Themes_model::activateTheme($themeName)) {
            flash()->success(sprintf(lang('admin::default.alert_success'), 'Theme ['.$theme->name.'] set as default '));
        }

        return $this->redirectBack();
    }

    public function edit_onSave($context, $themeCode = null)
    {
        $model = $this->formFindModelObject($themeCode);

        $this->initFormWidget($model, $context);

        if ($this->formValidate($model, $this->formWidget) === FALSE)
            return;

        $model->setAttribute('data', $this->formWidget->getSaveData());

        if ($model->save()) {
            flash()->success(sprintf(lang('admin::default.alert_success'), 'Theme settings updated '));
        }
        else {
            flash()->warning(sprintf(lang('admin::default.alert_error_nothing'), 'updated'));
        }

        return $this->refresh();
    }

    public function source_onSave($context, $themeCode = null)
    {
        $model = $this->formFindModelObject($themeCode);

        $this->initFormWidget($model, $context);

        if ($this->formValidate($model, $this->formWidget) === FALSE)
            return;

        $filename = array_get(post($this->formWidget->arrayName), 'file');
        $content = array_get(post($this->formWidget->arrayName), 'source');
        if (is_int($content))
            $filename = null;

        if (ThemeManager::instance()->writeFile($filename, $themeCode, $content)) {
            flash()->success(sprintf(lang('admin::default.alert_success'), 'Theme file ['.$filename.'] updated '));
        }

        if (post('close') != '1')
            session()->flash('Theme.customize', input('Theme.customize'));

        return $this->refresh();
    }

    public function source_onChooseFile($context, $themeCode = null) {
        $model = $this->formFindModelObject($themeCode);

        $this->initFormWidget($model, $context);

        if ($this->formValidate($model, $this->formWidget) === FALSE)
            return;

        session()->flash('Theme.customize', input('Theme.customize'));

        return $this->refresh();
    }

    public function upload_onUpload($context = null)
    {
        try {
            $themeManager = ThemeManager::instance();

            $this->validateUpload();

            $zipFile = Request::file('theme_zip');
            $themeManager->extractTheme($zipFile->path());

            flash()->success(sprintf(lang('admin::default.alert_success'), 'Theme uploaded '));

            return $this->redirect('themes');
        } catch (Exception $ex) {
            flash()->danger($ex->getMessage());

            return $this->refresh();
        }
    }

    public function delete_onDelete($context = null, $themeCode = null)
    {
        $theme = ThemeManager::instance()->findTheme($themeCode);
        $meta = $theme->config;

        if (Themes_model::deleteTheme($themeCode, (post('delete_data') == 1))) {
            $name = isset($meta['name']) ? $meta['name'] : '';

            flash()->success(sprintf(lang('admin::default.alert_success'), "Theme {$name} deleted "));
        }
        else {
            flash()->danger(lang('admin::default.alert_error_try_again'));
        }

        return $this->redirect('themes');
    }

    public function listOverrideColumnValue($record, $column, $alias = null)
    {
        if ($column->type != 'button' OR $column->columnName != 'default')
            return null;

        $attributes = $column->attributes;

        $column->iconCssClass = 'fa fa-star-o';
        if ($record->themeClass AND $record->themeClass->isActive()) {
            $column->iconCssClass = 'fa fa-star';
            $attributes['title'] = 'lang:system::themes.text_is_default';
            $attributes['data-request'] = null;
        }

        return $attributes;
    }

    public function initFormWidget($model, $context = null)
    {
        $configFile = $this->formConfig['configFile'];
        $config = $this->makeConfig($configFile, ['form']);
        $modelConfig = isset($config['form']) ? $config['form'] : [];

        if ($context != 'source') {
            $modelConfig['tabs']['fields'] = $model->getFieldsConfig();
        }

        $modelConfig['model'] = $model;
        $modelConfig['data'] = array_merge($model->getFieldValues(), $model->toArray());
        $modelConfig['arrayName'] = str_singular(strip_class_basename($model, '_model')).'[customize]';
        $modelConfig['context'] = $context;

        // Form Widget with extensibility
        $this->formWidget = $this->makeWidget('Admin\Widgets\Form', $modelConfig);
        $this->formWidget->bindEvent('form.extendFields', function ($fields) {
            $this->formExtendFields($this->formWidget, $fields);
        });

        $this->formWidget->bindToController();

        // Prep the optional toolbar widget
        if (isset($modelConfig['toolbar']) AND isset($this->widgets['toolbar'])) {
            $this->toolbarWidget = $this->widgets['toolbar'];
            $this->toolbarWidget->addButtons(array_get($modelConfig['toolbar'], 'buttons', []));
        }
    }

    public function formFindModelObject($recordId)
    {
        if (!strlen($recordId)) {
            throw new Exception(lang('admin::default.form.missing_id'));
        }

        $model = $this->createModel();

        // Prepare query and find model record
        $query = $model->newQuery();
        $result = $query->where('code', $recordId)->first();

        if (!$result) {
            throw new Exception(sprintf(lang('admin::default.form.not_found'), $recordId));
        }

        return $result;
    }

    public function formExtendFields($form, $fields)
    {
        $sourceField = $form->getField('source');
        $fileField = $form->getField('file');
        if (!$sourceField OR !$fileField)
            return;

        $file = array_get(session('Theme.customize', []), 'file', input('Theme.customize.file'));;
        $themeCode = $form->model->code;
        $fileField->options = $this->prepareFilesList($themeCode, $file);
        $fileField->value = $file;

        $themeManager = ThemeManager::instance();
        if (!$fileSource = $themeManager->readFile($file, $themeCode))
            return;

        $sourceField->value = $fileSource;

        switch (pathinfo($file, PATHINFO_EXTENSION)) {
            case 'js':
                $sourceField->config['mode'] = 'javascript';
                break;
            case 'css':
                $sourceField->config['mode'] = 'css';
                break;
            case 'php':
            default:
                $sourceField->config['mode'] = 'application/x-httpd-php';
                break;
        }
    }

    protected function createModel()
    {
        $class = $this->formConfig['model'];

        if (!isset($class) OR !strlen($class)) {
            throw new Exception(lang('admin::default.form.missing_model'));
        }

        $model = new $class;

        return $model;
    }

    protected function validateUpload()
    {
        $zipFile = Request::file('theme_zip');
        if (!Request::hasFile('theme_zip') OR !$zipFile->isValid())
            throw new SystemException("Please upload a zip file");

        $name = $zipFile->getClientOriginalName();
        $theme = $zipFile->extension();

        if (preg_match('/\s/', $name))
            throw new SystemException(lang('system::themes.error_upload_name'));

        if ($theme != 'zip')
            throw new SystemException(lang('system::themes.error_upload_type'));

        if ($zipFile->getError())
            throw new SystemException(lang('system::themes.error_php_upload').$zipFile->getErrorMessage());

        $name = substr($name, -strlen($theme));
        if (ThemeManager::instance()->hasTheme($name))
            throw new SystemException(lang('system::themes.error_theme_exists'));

        return TRUE;
    }

    public function formValidate($model, $form)
    {
        $rules = [];
        if ($form->context != 'source') {
            foreach ($model->getFieldsConfig() as $name => $field) {
                if (!array_key_exists('rules', $field))
                    continue;

                $dottedName = implode('.', name_to_array($name));
                $rules[] = [$dottedName, $field['label'], $field['rules']];
            }
        }
        else {
            $rules = [
                ['file', 'Source File', 'sometimes'],
                ['source', 'Source Content', 'sometimes'],
            ];
        }

        return $this->validatePasses($form->getSaveData(), $rules);
    }

    protected function prepareFilesList($themeCode, $currentFile = null)
    {
        $result = [];

        $themeManager = ThemeManager::instance();
        $list = $themeManager->listFiles($themeCode, ['_layouts', '_pages', '_partials']);
        foreach (array_sort($list) as $directory => $files) {
            foreach ($files as $file) {
                $group = pathinfo($file, PATHINFO_DIRNAME);
                $result[$file] = $group.'/'.pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $result;
    }
}