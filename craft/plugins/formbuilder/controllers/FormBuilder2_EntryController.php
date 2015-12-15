<?php
namespace Craft;

class FormBuilder2_EntryController extends BaseController
{

  protected $allowAnonymous = true;

  /**
   * Entries Index
   *
   */
  public function actionEntriesIndex()
  { 
    $formItems = craft()->formBuilder2_form->getAllForms();
    $settings = craft()->plugins->getPlugin('FormBuilder2')->getSettings();
    $plugins = craft()->plugins->getPlugin('FormBuilder2');

    $variables['title']       = 'FormBuilder2';
    $variables['formItems']   = $formItems;
    $variables['settings']    = $settings;
    $variables['navigation']  = $this->navigation();

    return $this->renderTemplate('formbuilder2/entries/index', $variables);
  }

  /**
   * View/Edit Entry
   *
   */
  public function actionViewEntry(array $variables = array())
  {
    $entry = craft()->formBuilder2_entry->getSubmissionById($variables['entryId']);
    if (empty($entry)) { throw new HttpException(404); }

    $files = '';
    if ($entry->files) {
      $files = [];
      foreach ($entry->files as $key => $value) {
        $files[] = craft()->assets->getFileById($value);
      }
    }

    $variables['crumbs'] = array(
      array('label' => Craft::t('FormBuilder 2'), 'url' => UrlHelper::getUrl('formbuilder2')),
      array('label' => Craft::t('Entries'),   'url' => UrlHelper::getUrl('formbuilder2/entries')),
      array('label' => $entry->id,  'url' => UrlHelper::getUrl('formbuilder2/entries/' . $entry->id . '/edit')),
    );

    $variables['entry']       = $entry;
    $variables['title']       = 'FormBuilder2';
    $variables['form']        = craft()->formBuilder2_form->getFormById($entry->formId);
    $variables['files']       = $files;
    $variables['submission']  = $entry->submission;
    $variables['navigation']  = $this->navigation();

    $this->renderTemplate('formbuilder2/entries/_view', $variables);
  }

  /**
   * Submit Entry
   *
   */
  public function actionSubmitEntry()
  {
    $form = craft()->formBuilder2_entry->getFormByHandle(craft()->request->getPost('formHandle'));
    
    // Set Up Form Submission
    $formFields = $form->fieldLayout->getFieldLayout()->getFields();
    $submission = craft()->request->getPost();
    $submissionData = $this->filterSubmissionKeys($submission);

    // Defaults
    $attributes                   = $form->getAttributes();
    $formSettings                 = $attributes['formSettings'];
    $spamProtectionSettings       = $attributes['spamProtectionSettings'];
    $messageSettings              = $attributes['messageSettings'];
    $notificationSettings         = $attributes['notificationSettings'];
    $files                      = '';
    $errorMessage               = [];

    // Prepare submissionEntry for processing
    $submissionEntry = new FormBuilder2_EntryModel();
    // Using Ajax
    if ($formSettings['ajaxSubmit'] == '1') {
      $this->requireAjaxRequest();
    } else {
      $this->requirePostRequest();
    }

    // Custom Redirect
    if ($formSettings['formRedirect']['customRedirect'] != '') {
      $redirectUrl = $formSettings['formRedirect']['customRedirectUrl'];
    }
    
    // Spam Protection | Timed Method
    if ($spamProtectionSettings['spamTimeMethod'] == '1') {
      $formSubmissionTime = (int)craft()->request->getPost('spamTimeMethod');
      $submissionDuration = time() - $formSubmissionTime;
      $allowedTime = (int)$spamProtectionSettings['spamTimeMethodTime'];
      if ($submissionDuration < $allowedTime) {
        $spamMethodOne = false;
        $errorMessage[] = Craft::t('You submitted too fast, you are robot!');
      } else {
        $spamMethodOne = true;
      }
    } else {
      $spamMethodOne = true;
    }

    // Spam Protection | Honeypot Method
    if ($spamProtectionSettings['spamHoneypotMethod'] == '1') {
      $honeypotField = craft()->request->getPost('email-address-new');
      if ($honeypotField != '') {
        $spamMethodTwo = false;
        $errorMessage[] = Craft::t('You tried the honey, you are robot bear!');
      } else {
        $spamMethodTwo = true;
      }
    } else {
      $spamMethodTwo = true;
    }

    // Validate Required Fields
    $validateRequired = craft()->formBuilder2_entry->validateEntry($form, $submissionData);
    // File Uploads
    if ($formSettings['hasFileUploads'] == '1') {
      foreach ($formFields as $key => $value) {
        $field = $value->getField();
        switch ($field->type) {
          case 'Assets':
            foreach ($_FILES as $key => $value) {
              if (!$value['tmp_name'] == '') {
                $fileModel = new AssetFileModel();
                $folderId = $field->settings['singleUploadLocationSource'][0];
                $sourceId = $field->settings['singleUploadLocationSource'][0];
                $fileModel->originalName  = $value['tmp_name'];
                $fileModel->sourceId      = $sourceId;
                $fileModel->folderId      = $folderId;
                $fileModel->filename      = IOHelper::getFileName($value['name']);
                $fileModel->kind          = IOHelper::getFileKind(IOHelper::getExtension($value['name']));
                $fileModel->size          = filesize($value['tmp_name']);
                if ($value['tmp_name']) {
                  $fileModel->dateModified  = IOHelper::getLastTimeModified($value['tmp_name']);
                }

                if ($fileModel->kind == 'image') {
                  list ($width, $height) = ImageHelper::getImageSize($value['tmp_name']);
                  $fileModel->width = $width;
                  $fileModel->height = $height;
                }
                $files[$key]     = $fileModel;
              }
            }
          break;
        }
      }
    }

    $submissionEntry->formId        = $form->id;
    $submissionEntry->title         = $form->name;
    $submissionEntry->files         = $files;
    $submissionEntry->submission    = $submissionData;

    // Process Errors
    if ($errorMessage) {
      craft()->urlManager->setRouteVariables(array(
        'errors' => $errorMessage
      ));
    }

    // Process Submission Entry
    if (!$errorMessage && $spamMethodOne && $spamMethodTwo && $validateRequired) {

      $submissionResponseId = craft()->formBuilder2_entry->processSubmissionEntry($submissionEntry);
      
      // Notify Admin of Submission
      if ($notificationSettings['notifySubmission'] == '1') {
        $this->notifyAdminOfSubmission($submissionResponseId, $form);
      }

      // Messages
      if ($formSettings['ajaxSubmit'] == '1') {
        $this->returnJson(
          ['success' => true, 'message' => $messageSettings['successMessage'], 'form' => $form]
        );
      } else {
        craft()->userSession->setFlash('success', $messageSettings['successMessage']);
        if ($formSettings['formRedirect']['customRedirect'] != '') {
          $this->redirect($redirectUrl);
        } else {
          $this->redirectToPostedUrl();
        }
      }

    } else {
      if ($formSettings['ajaxSubmit'] == '1') {
        $this->returnJson(
          ['error' => true, 'message' => $messageSettings['errorMessage'], 'form' => $form]
        );
      } else {
        craft()->userSession->setFlash('error', $messageSettings['errorMessage']);
      }
    }
  }

  /**
   * Delete Submission
   *
   */
  public function actionDeleteSubmission()
  {
    $this->requirePostRequest();
    $entryId = craft()->request->getRequiredPost('entryId');

    if (craft()->elements->deleteElementById($entryId)) {
      craft()->userSession->setNotice(Craft::t('Entry deleted.'));
      $this->redirectToPostedUrl();
      craft()->userSession->setError(Craft::t('Couldn’t delete entry.'));
    }
  }

  /**
   * Notify Admin of Submission
   *
   */
  protected function notifyAdminOfSubmission($submissionResponseId, $form)
  {  
    $submission   = craft()->formBuilder2_entry->getSubmissionById($submissionResponseId);
    // $data         = new \stdClass($data);
    $files        = [];
    $postUploads  = $submission->files;
    $postData     = $submission->submission;
    $postData     = $this->filterSubmissionKeys($postData);

    craft()->path->setTemplatesPath(craft()->path->getPluginsPath());
    $templatePath = craft()->path->getPluginsPath() . 'plugins/formbuilder2/templates/email/';
    $customTemplatePath = craft()->path->getPluginsPath() . 'formbuilder2/templates/custom/email/';
    $extension = '.twig';

    // Uploaded Files
    if ($postUploads) {
      foreach ($postUploads as $key => $id) {
        $criteria         = craft()->elements->getCriteria(ElementType::Asset);
        $criteria->id     = $id;
        $criteria->limit  = 1;
        $files[]          = $criteria->find();
      }
    }

    $attributes             = $form->getAttributes();
    $formSettings           = $attributes['formSettings'];
    $notificationSettings   = $attributes['notificationSettings'];
    $templateSettings       = $notificationSettings['templateSettings'];

    // Get Logo
    if ($notificationSettings['templateSettings']['emailCustomLogo'] != '') {
      $criteria         = craft()->elements->getCriteria(ElementType::Asset);
      $criteria->id     = $notificationSettings['templateSettings']['emailCustomLogo'];
      $criteria->limit  = 1;
      $customLogo       = $criteria->find();
    } else {
      $customLogo = '';
    }

    $variables['form']                  = $form;
    $variables['files']                 = $files;
    $variables['formSettings']          = $formSettings;
    $variables['emailSettings']         = $notificationSettings['emailSettings'];
    $variables['templateSettings']      = $notificationSettings['templateSettings'];
    if ($notificationSettings['templateSettings']['emailCustomLogo'] != '') {
      $variables['customLogo']          = $customLogo;
    }
    if ($notificationSettings['emailSettings']['sendSubmissionData'] == '1') {
      $variables['data']                = $postData;
    }

    if ($templateSettings['emailTemplateStyle'] == 'html') {
      if (IOHelper::fileExists($customTemplatePath . 'html' . $extension)) {
        $message  = craft()->templates->render('formbuilder2/templates/custom/email/html', $variables);
      } else {
        $message  = craft()->templates->render('formbuilder2/templates/email/html', $variables);
      }
    } else {
      if (IOHelper::fileExists($customTemplatePath . 'text' . $extension)) {
        $message  = craft()->templates->render('formbuilder2/templates/custom/email/text', $variables);
      } else {
        $message  = craft()->templates->render('formbuilder2/templates/email/text', $variables);
      }
    }

    if (craft()->formBuilder2_entry->sendEmailNotification($form, $postUploads, $message, true, null)) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Filter Out Unused Post Submission
   *
   */
  protected function filterSubmissionKeys($submission)
  {
    $filterKeys = array(
      'action',
      'formRedirect',
      'formHandle',
      'spamTimeMethod',
      'email-address-new',
    );
    if (is_array($submission)) {
      foreach ($submission as $k => $v) {
        if (in_array($k, $filterKeys)) {
          unset($submission[$k]);
        }
      }
    }
    return $submission;
  }
  
  /**
   * Sidebar Navigation
   *
   */
  public function navigation()
  {
    $navigationSections = [
      [
        'heading' => Craft::t('Menu'),
        'nav'     => [
          [
            'label' => Craft::t('Dashboard'),
            'icon'  => 'tachometer',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2'),
          ],
          [
            'label' => Craft::t('Forms'),
            'icon'  => 'list-alt',
            'extra' => craft()->formBuilder2_form->getTotalForms(),
            'url'   => UrlHelper::getCpUrl('formbuilder2/forms'),
          ],
          [
            'label' => Craft::t('Entries'),
            'icon'  => 'file-text-o',
            'extra' => craft()->formBuilder2_entry->getTotalEntries(),
            'url'   => UrlHelper::getCpUrl('formbuilder2/entries'),
          ],
        ]
      ],
      [
        'heading' => Craft::t('Quick Links'),
        'nav'     => [
          [
            'label' => Craft::t('Create New Form'),
            'icon'  => 'pencil-square-o',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2/forms/new'),
          ],
        ]
      ],
      [
        'heading' => Craft::t('Tools'),
        'nav'     => [
          [
            'label' => Craft::t('Export'),
            'icon'  => 'share-square-o',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2/tools/export'),
          ],
          [
            'label' => Craft::t('Configuration'),
            'icon'  => 'sliders',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2/tools/configuration'),
          ],
        ]
      ],
    ];
    return $navigationSections;
  }

}
