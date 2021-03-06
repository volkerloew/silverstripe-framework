<?php

namespace SilverStripe\Forms;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Session;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\SS_List;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Security\NullSecurityToken;
use SilverStripe\View\SSViewer;

/**
 * Base class for all forms.
 * The form class is an extensible base for all forms on a SilverStripe application.  It can be used
 * either by extending it, and creating processor methods on the subclass, or by creating instances
 * of form whose actions are handled by the parent controller.
 *
 * In either case, if you want to get a form to do anything, it must be inextricably tied to a
 * controller.  The constructor is passed a controller and a method on that controller.  This method
 * should return the form object, and it shouldn't require any arguments.  Parameters, if necessary,
 * can be passed using the URL or get variables.  These restrictions are in place so that we can
 * recreate the form object upon form submission, without the use of a session, which would be too
 * resource-intensive.
 *
 * You will need to create at least one method for processing the submission (through {@link FormAction}).
 * This method will be passed two parameters: the raw request data, and the form object.
 * Usually you want to save data into a {@link DataObject} by using {@link saveInto()}.
 * If you want to process the submitted data in any way, please use {@link getData()} rather than
 * the raw request data.
 *
 * <h2>Validation</h2>
 * Each form needs some form of {@link Validator} to trigger the {@link FormField->validate()} methods for each field.
 * You can't disable validator for security reasons, because crucial behaviour like extension checks for file uploads
 * depend on it.
 * The default validator is an instance of {@link RequiredFields}.
 * If you want to enforce serverside-validation to be ignored for a specific {@link FormField},
 * you need to subclass it.
 *
 * <h2>URL Handling</h2>
 * The form class extends {@link RequestHandler}, which means it can
 * be accessed directly through a URL. This can be handy for refreshing
 * a form by ajax, or even just displaying a single form field.
 * You can find out the base URL for your form by looking at the
 * <form action="..."> value. For example, the edit form in the CMS would be located at
 * "admin/EditForm". This URL will render the form without its surrounding
 * template when called through GET instead of POST.
 *
 * By appending to this URL, you can render individual form elements
 * through the {@link FormField->FieldHolder()} method.
 * For example, the "URLSegment" field in a standard CMS form would be
 * accessible through "admin/EditForm/field/URLSegment/FieldHolder".
 */
class Form extends RequestHandler
{
    use FormMessage;

    /**
     * Form submission data is URL encoded
     */
    const ENC_TYPE_URLENCODED = 'application/x-www-form-urlencoded';

    /**
     * Form submission data is multipart form
     */
    const ENC_TYPE_MULTIPART  = 'multipart/form-data';

    /**
     * Accessed by Form.ss; modified by {@link formHtmlContent()}.
     * A performance enhancement over the generate-the-form-tag-and-then-remove-it code that was there previously
     *
     * @var bool
     */
    public $IncludeFormTag = true;

    /**
     * @var FieldList
     */
    protected $fields;

    /**
     * @var FieldList
     */
    protected $actions;

    /**
     * @var Controller
     */
    protected $controller;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var callable {@see setValidationResponseCallback()}
     */
    protected $validationResponseCallback;

    /**
     * @var string
     */
    protected $formMethod = "POST";

    /**
     * @var boolean
     */
    protected $strictFormMethodCheck = false;

    /**
     * @var DataObject|null $record Populated by {@link loadDataFrom()}.
     */
    protected $record;

    /**
     * Keeps track of whether this form has a default action or not.
     * Set to false by $this->disableDefaultAction();
     *
     * @var boolean
     */
    protected $hasDefaultAction = true;

    /**
     * Target attribute of form-tag.
     * Useful to open a new window upon
     * form submission.
     *
     * @var string|null
     */
    protected $target;

    /**
     * Legend value, to be inserted into the
     * <legend> element before the <fieldset>
     * in Form.ss template.
     *
     * @var string|null
     */
    protected $legend;

    /**
     * The SS template to render this form HTML into.
     * Default is "Form", but this can be changed to
     * another template for customisation.
     *
     * @see Form->setTemplate()
     * @var string|null
     */
    protected $template;

    /**
     * @var callable|null
     */
    protected $buttonClickedFunc;

    /**
     * Should we redirect the user back down to the
     * the form on validation errors rather then just the page
     *
     * @var bool
     */
    protected $redirectToFormOnValidationError = false;

    /**
     * @var bool
     */
    protected $security = true;

    /**
     * @var SecurityToken|null
     */
    protected $securityToken = null;

    /**
     * @var array $extraClasses List of additional CSS classes for the form tag.
     */
    protected $extraClasses = array();

    /**
     * @config
     * @var array $default_classes The default classes to apply to the Form
     */
    private static $default_classes = array();

    /**
     * @var string|null
     */
    protected $encType;

    /**
     * @var array Any custom form attributes set through {@link setAttributes()}.
     * Some attributes are calculated on the fly, so please use {@link getAttributes()} to access them.
     */
    protected $attributes = array();

    /**
     * @var array
     */
    protected $validationExemptActions = array();

    private static $allowed_actions = array(
        'handleField',
        'httpSubmission',
        'forTemplate',
    );

    private static $casting = array(
        'AttributesHTML' => 'HTMLFragment',
        'FormAttributes' => 'HTMLFragment',
        'FormName' => 'Text',
        'Legend' => 'HTMLFragment',
    );

    /**
     * @var FormTemplateHelper
     */
    private $templateHelper = null;

    /**
     * @ignore
     */
    private $htmlID = null;

    /**
     * @ignore
     */
    private $formActionPath = false;

    /**
     * @var bool
     */
    protected $securityTokenAdded = false;

    /**
     * Create a new form, with the given fields an action buttons.
     *
     * @param Controller $controller The parent controller, necessary to create the appropriate form action tag.
     * @param string $name The method on the controller that will return this form object.
     * @param FieldList $fields All of the fields in the form - a {@link FieldList} of {@link FormField} objects.
     * @param FieldList $actions All of the action buttons in the form - a {@link FieldLis} of
     *                           {@link FormAction} objects
     * @param Validator|null $validator Override the default validator instance (Default: {@link RequiredFields})
     */
    public function __construct($controller, $name, FieldList $fields, FieldList $actions, Validator $validator = null)
    {
        parent::__construct();

        $fields->setForm($this);
        $actions->setForm($this);

        $this->fields = $fields;
        $this->actions = $actions;
        $this->controller = $controller;
        $this->setName($name);

        if (!$this->controller) {
            user_error("$this->class form created without a controller", E_USER_ERROR);
        }

        // Form validation
        $this->validator = ($validator) ? $validator : new RequiredFields();
        $this->validator->setForm($this);

        // Form error controls
        $this->restoreFormState();

        // Check if CSRF protection is enabled, either on the parent controller or from the default setting. Note that
        // method_exists() is used as some controllers (e.g. GroupTest) do not always extend from Object.
        if (method_exists($controller, 'securityTokenEnabled') || (method_exists($controller, 'hasMethod')
                && $controller->hasMethod('securityTokenEnabled'))) {
            $securityEnabled = $controller->securityTokenEnabled();
        } else {
            $securityEnabled = SecurityToken::is_enabled();
        }

        $this->securityToken = ($securityEnabled) ? new SecurityToken() : new NullSecurityToken();

        $this->setupDefaultClasses();
    }

    /**
     * @var array
     */
    private static $url_handlers = array(
        'field/$FieldName!' => 'handleField',
        'POST ' => 'httpSubmission',
        'GET ' => 'httpSubmission',
        'HEAD ' => 'httpSubmission',
    );

    /**
     * Load form state from session state
     * @return $this
     */
    public function restoreFormState()
    {
        // Restore messages
        $result = $this->getSessionValidationResult();
        if (isset($result)) {
            $this->loadMessagesFrom($result);
        }

        // load data in from previous submission upon error
        $data = $this->getSessionData();
        if (isset($data)) {
            $this->loadDataFrom($data);
        }
        return $this;
    }

    /**
     * Flush persistant form state details
     */
    public function clearFormState()
    {
        Session::clear("FormInfo.{$this->FormName()}.result");
        Session::clear("FormInfo.{$this->FormName()}.data");
    }

    /**
     * Return any form data stored in the session
     *
     * @return array
     */
    public function getSessionData()
    {
        return Session::get("FormInfo.{$this->FormName()}.data");
    }

    /**
     * Store the given form data in the session
     *
     * @param array $data
     */
    public function setSessionData($data)
    {
        Session::set("FormInfo.{$this->FormName()}.data", $data);
    }

    /**
     * Return any ValidationResult instance stored for this object
     *
     * @return ValidationResult The ValidationResult object stored in the session
     */
    public function getSessionValidationResult()
    {
        $resultData = Session::get("FormInfo.{$this->FormName()}.result");
        if (isset($resultData)) {
            return unserialize($resultData);
        }
        return null;
    }

    /**
     * Sets the ValidationResult in the session to be used with the next view of this form.
     * @param ValidationResult $result The result to save
     * @param bool $combineWithExisting If true, then this will be added to the existing result.
     */
    public function setSessionValidationResult(ValidationResult $result, $combineWithExisting = false)
    {
        // Combine with existing result
        if ($combineWithExisting) {
            $existingResult = $this->getSessionValidationResult();
            if ($existingResult) {
                if ($result) {
                    $existingResult->combineAnd($result);
                } else {
                    $result = $existingResult;
                }
            }
        }

        // Serialise
        $resultData = $result ? serialize($result) : null;
        Session::set("FormInfo.{$this->FormName()}.result", $resultData);
    }

    public function clearMessage()
    {
        $this->setMessage(null);
        $this->clearFormState();
    }

    /**
     * Populate this form with messages from the given ValidationResult.
     * Note: This will not clear any pre-existing messages
     *
     * @param ValidationResult $result
     * @return $this
     */
    public function loadMessagesFrom($result)
    {
        // Set message on either a field or the parent form
        foreach ($result->getMessages() as $message) {
            $fieldName = $message['fieldName'];
            if ($fieldName) {
                $owner = $this->fields->dataFieldByName($fieldName) ?: $this;
            } else {
                $owner = $this;
            }
            $owner->setMessage($message['message'], $message['messageType'], $message['messageCast']);
        }
        return $this;
    }

    /**
     * Set message on a given field name. This message will not persist via redirect.
     *
     * @param string $fieldName
     * @param string $message
     * @param string $messageType
     * @param string $messageCast
     * @return $this
     */
    public function setFieldMessage(
        $fieldName,
        $message,
        $messageType = ValidationResult::TYPE_ERROR,
        $messageCast = ValidationResult::CAST_TEXT
    ) {
        $field = $this->fields->dataFieldByName($fieldName);
        if ($field) {
            $field->setMessage($message, $messageType, $messageCast);
        }
        return $this;
    }

    public function castingHelper($field)
    {
        // Override casting for field message
        if (strcasecmp($field, 'Message') === 0 && ($helper = $this->getMessageCastingHelper())) {
            return $helper;
        }
        return parent::castingHelper($field);
    }

    /**
     * set up the default classes for the form. This is done on construct so that the default classes can be removed
     * after instantiation
     */
    protected function setupDefaultClasses()
    {
        $defaultClasses = self::config()->get('default_classes');
        if ($defaultClasses) {
            foreach ($defaultClasses as $class) {
                $this->addExtraClass($class);
            }
        }
    }

    /**
     * Handle a form submission.  GET and POST requests behave identically.
     * Populates the form with {@link loadDataFrom()}, calls {@link validate()},
     * and only triggers the requested form action/method
     * if the form is valid.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     */
    public function httpSubmission($request)
    {
        // Strict method check
        if ($this->strictFormMethodCheck) {
            // Throws an error if the method is bad...
            if ($this->formMethod != $request->httpMethod()) {
                $response = Controller::curr()->getResponse();
                $response->addHeader('Allow', $this->formMethod);
                $this->httpError(405, _t("Form.BAD_METHOD", "This form requires a ".$this->formMethod." submission"));
            }

            // ...and only uses the variables corresponding to that method type
            $vars = $this->formMethod == 'GET' ? $request->getVars() : $request->postVars();
        } else {
            $vars = $request->requestVars();
        }

        // Ensure we only process saveable fields (non structural, readonly, or disabled)
        $allowedFields = array_keys($this->Fields()->saveableFields());

        // Populate the form
        $this->loadDataFrom($vars, true, $allowedFields);

        // Protection against CSRF attacks
        // @todo Move this to SecurityTokenField::validate()
        $token = $this->getSecurityToken();
        if (! $token->checkRequest($request)) {
            $securityID = $token->getName();
            if (empty($vars[$securityID])) {
                $this->httpError(400, _t(
                    "Form.CSRF_FAILED_MESSAGE",
                    "There seems to have been a technical problem. Please click the back button, ".
                    "refresh your browser, and try again."
                ));
            } else {
                // Clear invalid token on refresh
                $this->clearFormState();
                $data = $this->getData();
                unset($data[$securityID]);
                $this->setSessionData($data);
                $this->sessionError(_t(
                    "Form.CSRF_EXPIRED_MESSAGE",
                    "Your session has expired. Please re-submit the form."
                ));

                // Return the user
                return $this->controller->redirectBack();
            }
        }

        // Determine the action button clicked
        $funcName = null;
        foreach ($vars as $paramName => $paramVal) {
            if (substr($paramName, 0, 7) == 'action_') {
                // Break off querystring arguments included in the action
                if (strpos($paramName, '?') !== false) {
                    list($paramName, $paramVars) = explode('?', $paramName, 2);
                    $newRequestParams = array();
                    parse_str($paramVars, $newRequestParams);
                    $vars = array_merge((array)$vars, (array)$newRequestParams);
                }

                // Cleanup action_, _x and _y from image fields
                $funcName = preg_replace(array('/^action_/','/_x$|_y$/'), '', $paramName);
                break;
            }
        }

        // If the action wasn't set, choose the default on the form.
        if (!isset($funcName) && $defaultAction = $this->defaultAction()) {
            $funcName = $defaultAction->actionName();
        }

        if (isset($funcName)) {
            $this->setButtonClicked($funcName);
        }

        // Permission checks (first on controller, then falling back to form)
        if (// Ensure that the action is actually a button or method on the form,
            // and not just a method on the controller.
            $this->controller->hasMethod($funcName)
            && !$this->controller->checkAccessAction($funcName)
            // If a button exists, allow it on the controller
            // buttonClicked() validates that the action set above is valid
            && !$this->buttonClicked()
        ) {
            return $this->httpError(
                403,
                sprintf('Action "%s" not allowed on controller (Class: %s)', $funcName, get_class($this->controller))
            );
        } elseif ($this->hasMethod($funcName)
            && !$this->checkAccessAction($funcName)
            // No checks for button existence or $allowed_actions is performed -
            // all form methods are callable (e.g. the legacy "callfieldmethod()")
        ) {
            return $this->httpError(
                403,
                sprintf('Action "%s" not allowed on form (Name: "%s")', $funcName, $this->name)
            );
        }

        // Action handlers may throw ValidationExceptions.
        try {
            // Or we can use the Valiator attached to the form
            $result = $this->validationResult();
            if (!$result->isValid()) {
                return $this->getValidationErrorResponse($result);
            }

            // First, try a handler method on the controller (has been checked for allowed_actions above already)
            if ($this->controller->hasMethod($funcName)) {
                return $this->controller->$funcName($vars, $this, $request);
            }

            // Otherwise, try a handler method on the form object.
            if ($this->hasMethod($funcName)) {
                return $this->$funcName($vars, $this, $request);
            }

            // Check for inline actions
            if ($field = $this->checkFieldsForAction($this->Fields(), $funcName)) {
                return $field->$funcName($vars, $this, $request);
            }
        } catch (ValidationException $e) {
            // The ValdiationResult contains all the relevant metadata
            $result = $e->getResult();
            $this->loadMessagesFrom($result);
            return $this->getValidationErrorResponse($result);
        }

        return $this->httpError(404);
    }

    /**
     * @param string $action
     * @return bool
     */
    public function checkAccessAction($action)
    {
        if (parent::checkAccessAction($action)) {
            return true;
        }

        $actions = $this->getAllActions();
        foreach ($actions as $formAction) {
            if ($formAction->actionName() === $action) {
                return true;
            }
        }

            // Always allow actions on fields
        $field = $this->checkFieldsForAction($this->Fields(), $action);
        if ($field && $field->checkAccessAction($action)) {
            return true;
        }

        return false;
    }

    /**
     * @return callable
     */
    public function getValidationResponseCallback()
    {
        return $this->validationResponseCallback;
    }

    /**
     * Overrules validation error behaviour in {@link httpSubmission()}
     * when validation has failed. Useful for optional handling of a certain accepted content type.
     *
     * The callback can opt out of handling specific responses by returning NULL,
     * in which case the default form behaviour will kick in.
     *
     * @param $callback
     * @return self
     */
    public function setValidationResponseCallback($callback)
    {
        $this->validationResponseCallback = $callback;

        return $this;
    }

    /**
     * Returns the appropriate response up the controller chain
     * if {@link validate()} fails (which is checked prior to executing any form actions).
     * By default, returns different views for ajax/non-ajax request, and
     * handles 'application/json' requests with a JSON object containing the error messages.
     * Behaviour can be influenced by setting {@link $redirectToFormOnValidationError},
     * and can be overruled by setting {@link $validationResponseCallback}.
     *
     * @param ValidationResult $result
     * @return HTTPResponse
     */
    protected function getValidationErrorResponse(ValidationResult $result)
    {
        // Check for custom handling mechanism
        $callback = $this->getValidationResponseCallback();
        if ($callback && $callbackResponse = call_user_func($callback, $result)) {
            return $callbackResponse;
        }

        // Check if handling via ajax
        if ($this->getRequest()->isAjax()) {
            return $this->getAjaxErrorResponse($result);
        }

        // Prior to redirection, persist this result in session to re-display on redirect
        $this->setSessionValidationResult($result);
        $this->setSessionData($this->getData());

        // Determine redirection method
        if ($this->getRedirectToFormOnValidationError() && ($pageURL = $this->getRedirectReferer())) {
            return $this->controller->redirect($pageURL . '#' . $this->FormName());
        }
        return $this->controller->redirectBack();
    }

    /**
     * Build HTTP error response for ajax requests
     *
     * @internal called from {@see Form::getValidationErrorResponse}
     * @param ValidationResult $result
     * @return HTTPResponse
     */
    protected function getAjaxErrorResponse(ValidationResult $result)
    {
        // Ajax form submissions accept json encoded errors by default
        $acceptType = $this->getRequest()->getHeader('Accept');
        if (strpos($acceptType, 'application/json') !== false) {
            // Send validation errors back as JSON with a flag at the start
            $response = new HTTPResponse(Convert::array2json($result->getMessages()));
            $response->addHeader('Content-Type', 'application/json');
            return $response;
        }

        // Send the newly rendered form tag as HTML
        $this->loadMessagesFrom($result);
        $response = new HTTPResponse($this->forTemplate());
        $response->addHeader('Content-Type', 'text/html');
        return $response;
    }

    /**
     * Get referrer to redirect back to and safely validates it
     *
     * @internal called from {@see Form::getValidationErrorResponse}
     * @return string|null
     */
    protected function getRedirectReferer()
    {
        $pageURL = $this->getRequest()->getHeader('Referer');
        if (!$pageURL) {
            return null;
        }
        if (!Director::is_site_url($pageURL)) {
            return null;
        }

        // Remove existing pragmas
        $pageURL = preg_replace('/(#.*)/', '', $pageURL);
        return Director::absoluteURL($pageURL);
    }

    /**
     * Fields can have action to, let's check if anyone of the responds to $funcname them
     *
     * @param SS_List|array $fields
     * @param callable $funcName
     * @return FormField
     */
    protected function checkFieldsForAction($fields, $funcName)
    {
        foreach ($fields as $field) {
            /** @skipUpgrade */
            if (method_exists($field, 'FieldList')) {
                if ($field = $this->checkFieldsForAction($field->FieldList(), $funcName)) {
                    return $field;
                }
            } elseif ($field->hasMethod($funcName) && $field->checkAccessAction($funcName)) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Handle a field request.
     * Uses {@link Form->dataFieldByName()} to find a matching field,
     * and falls back to {@link FieldList->fieldByName()} to look
     * for tabs instead. This means that if you have a tab and a
     * formfield with the same name, this method gives priority
     * to the formfield.
     *
     * @param HTTPRequest $request
     * @return FormField
     */
    public function handleField($request)
    {
        $field = $this->Fields()->dataFieldByName($request->param('FieldName'));

        if ($field) {
            return $field;
        } else {
            // falling back to fieldByName, e.g. for getting tabs
            return $this->Fields()->fieldByName($request->param('FieldName'));
        }
    }

    /**
     * Convert this form into a readonly form
     */
    public function makeReadonly()
    {
        $this->transform(new ReadonlyTransformation());
    }

    /**
     * Set whether the user should be redirected back down to the
     * form on the page upon validation errors in the form or if
     * they just need to redirect back to the page
     *
     * @param bool $bool Redirect to form on error?
     * @return $this
     */
    public function setRedirectToFormOnValidationError($bool)
    {
        $this->redirectToFormOnValidationError = $bool;
        return $this;
    }

    /**
     * Get whether the user should be redirected back down to the
     * form on the page upon validation errors
     *
     * @return bool
     */
    public function getRedirectToFormOnValidationError()
    {
        return $this->redirectToFormOnValidationError;
    }

    /**
     * @param FormTransformation $trans
     */
    public function transform(FormTransformation $trans)
    {
        $newFields = new FieldList();
        foreach ($this->fields as $field) {
            $newFields->push($field->transform($trans));
        }
        $this->fields = $newFields;

        $newActions = new FieldList();
        foreach ($this->actions as $action) {
            $newActions->push($action->transform($trans));
        }
        $this->actions = $newActions;


        // We have to remove validation, if the fields are not editable ;-)
        if ($this->validator) {
            $this->validator->removeValidation();
        }
    }

    /**
     * Get the {@link Validator} attached to this form.
     * @return Validator
     */
    public function getValidator()
    {
        return $this->validator;
    }

    /**
     * Set the {@link Validator} on this form.
     * @param Validator $validator
     * @return $this
     */
    public function setValidator(Validator $validator)
    {
        if ($validator) {
            $this->validator = $validator;
            $this->validator->setForm($this);
        }
        return $this;
    }

    /**
     * Remove the {@link Validator} from this from.
     */
    public function unsetValidator()
    {
        $this->validator = null;
        return $this;
    }

    /**
     * Set actions that are exempt from validation
     *
     * @param array
     * @return $this
     */
    public function setValidationExemptActions($actions)
    {
        $this->validationExemptActions = $actions;
        return $this;
    }

    /**
     * Get a list of actions that are exempt from validation
     *
     * @return array
     */
    public function getValidationExemptActions()
    {
        return $this->validationExemptActions;
    }

    /**
     * Passed a FormAction, returns true if that action is exempt from Form validation
     *
     * @param FormAction $action
     * @return bool
     */
    public function actionIsValidationExempt($action)
    {
        if ($action->getValidationExempt()) {
            return true;
        }
        if (in_array($action->actionName(), $this->getValidationExemptActions())) {
            return true;
        }
        return false;
    }

    /**
     * Generate extra special fields - namely the security token field (if required).
     *
     * @return FieldList
     */
    public function getExtraFields()
    {
        $extraFields = new FieldList();

        $token = $this->getSecurityToken();
        if ($token) {
            $tokenField = $token->updateFieldSet($this->fields);
            if ($tokenField) {
                $tokenField->setForm($this);
            }
        }
        $this->securityTokenAdded = true;

        // add the "real" HTTP method if necessary (for PUT, DELETE and HEAD)
        if (strtoupper($this->FormMethod()) != $this->FormHttpMethod()) {
            $methodField = new HiddenField('_method', '', $this->FormHttpMethod());
            $methodField->setForm($this);
            $extraFields->push($methodField);
        }

        return $extraFields;
    }

    /**
     * Return the form's fields - used by the templates
     *
     * @return FieldList The form fields
     */
    public function Fields()
    {
        foreach ($this->getExtraFields() as $field) {
            if (!$this->fields->fieldByName($field->getName())) {
                $this->fields->push($field);
            }
        }

        return $this->fields;
    }

    /**
     * Return all <input type="hidden"> fields
     * in a form - including fields nested in {@link CompositeFields}.
     * Useful when doing custom field layouts.
     *
     * @return FieldList
     */
    public function HiddenFields()
    {
        return $this->Fields()->HiddenFields();
    }

    /**
     * Return all fields except for the hidden fields.
     * Useful when making your own simplified form layouts.
     */
    public function VisibleFields()
    {
        return $this->Fields()->VisibleFields();
    }

    /**
     * Setter for the form fields.
     *
     * @param FieldList $fields
     * @return $this
     */
    public function setFields($fields)
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * Return the form's action buttons - used by the templates
     *
     * @return FieldList The action list
     */
    public function Actions()
    {
        return $this->actions;
    }

    /**
     * Setter for the form actions.
     *
     * @param FieldList $actions
     * @return $this
     */
    public function setActions($actions)
    {
        $this->actions = $actions;
        return $this;
    }

    /**
     * Unset all form actions
     */
    public function unsetAllActions()
    {
        $this->actions = new FieldList();
        return $this;
    }

    /**
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function setAttribute($name, $value)
    {
        $this->attributes[$name] = $value;
        return $this;
    }

    /**
     * @param string $name
     * @return string
     */
    public function getAttribute($name)
    {
        if (isset($this->attributes[$name])) {
            return $this->attributes[$name];
        }
        return null;
    }

    /**
     * @return array
     */
    public function getAttributes()
    {
        $attrs = array(
            'id' => $this->FormName(),
            'action' => $this->FormAction(),
            'method' => $this->FormMethod(),
            'enctype' => $this->getEncType(),
            'target' => $this->target,
            'class' => $this->extraClass(),
        );

        if ($this->validator && $this->validator->getErrors()) {
            if (!isset($attrs['class'])) {
                $attrs['class'] = '';
            }
            $attrs['class'] .= ' validationerror';
        }

        $attrs = array_merge($attrs, $this->attributes);

        return $attrs;
    }

    /**
     * Return the attributes of the form tag - used by the templates.
     *
     * @param array $attrs Custom attributes to process. Falls back to {@link getAttributes()}.
     * If at least one argument is passed as a string, all arguments act as excludes by name.
     *
     * @return string HTML attributes, ready for insertion into an HTML tag
     */
    public function getAttributesHTML($attrs = null)
    {
        $exclude = (is_string($attrs)) ? func_get_args() : null;

        // Figure out if we can cache this form
        // - forms with validation shouldn't be cached, cos their error messages won't be shown
        // - forms with security tokens shouldn't be cached because security tokens expire
        $needsCacheDisabled = false;
        if ($this->getSecurityToken()->isEnabled()) {
            $needsCacheDisabled = true;
        }
        if ($this->FormMethod() != 'GET') {
            $needsCacheDisabled = true;
        }
        if (!($this->validator instanceof RequiredFields) || count($this->validator->getRequired())) {
            $needsCacheDisabled = true;
        }

        // If we need to disable cache, do it
        if ($needsCacheDisabled) {
            HTTP::set_cache_age(0);
        }

        $attrs = $this->getAttributes();

        // Remove empty
        $attrs = array_filter((array)$attrs, create_function('$v', 'return ($v || $v === 0);'));

        // Remove excluded
        if ($exclude) {
            $attrs = array_diff_key($attrs, array_flip($exclude));
        }

        // Prepare HTML-friendly 'method' attribute (lower-case)
        if (isset($attrs['method'])) {
            $attrs['method'] = strtolower($attrs['method']);
        }

        // Create markup
        $parts = array();
        foreach ($attrs as $name => $value) {
            $parts[] = ($value === true) ? "{$name}=\"{$name}\"" : "{$name}=\"" . Convert::raw2att($value) . "\"";
        }

        return implode(' ', $parts);
    }

    public function FormAttributes()
    {
        return $this->getAttributesHTML();
    }

    /**
     * Set the target of this form to any value - useful for opening the form contents in a new window or refreshing
     * another frame
    *
     * @param string|FormTemplateHelper
    */
    public function setTemplateHelper($helper)
    {
        $this->templateHelper = $helper;
    }

    /**
     * Return a {@link FormTemplateHelper} for this form. If one has not been
     * set, return the default helper.
     *
     * @return FormTemplateHelper
     */
    public function getTemplateHelper()
    {
        if ($this->templateHelper) {
            if (is_string($this->templateHelper)) {
                return Injector::inst()->get($this->templateHelper);
            }

            return $this->templateHelper;
        }

        return FormTemplateHelper::singleton();
    }

    /**
     * Set the target of this form to any value - useful for opening the form
     * contents in a new window or refreshing another frame.
     *
     * @param string $target The value of the target
     * @return $this
     */
    public function setTarget($target)
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Set the legend value to be inserted into
     * the <legend> element in the Form.ss template.
     * @param string $legend
     * @return $this
     */
    public function setLegend($legend)
    {
        $this->legend = $legend;
        return $this;
    }

    /**
     * Set the SS template that this form should use
     * to render with. The default is "Form".
     *
     * @param string $template The name of the template (without the .ss extension)
     * @return $this
     */
    public function setTemplate($template)
    {
        $this->template = $template;
        return $this;
    }

    /**
     * Return the template to render this form with.
     *
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * Returs the ordered list of preferred templates for rendering this form
     * If the template isn't set, then default to the
     * form class name e.g "Form".
     *
     * @return array
     */
    public function getTemplates()
    {
        $templates = SSViewer::get_templates_by_class(get_class($this), '', __CLASS__);
        // Prefer any custom template
        if ($this->getTemplate()) {
            array_unshift($templates, $this->getTemplate());
        }
        return $templates;
    }

    /**
     * Returns the encoding type for the form.
     *
     * By default this will be URL encoded, unless there is a file field present
     * in which case multipart is used. You can also set the enc type using
     * {@link setEncType}.
     */
    public function getEncType()
    {
        if ($this->encType) {
            return $this->encType;
        }

        if ($fields = $this->fields->dataFields()) {
            foreach ($fields as $field) {
                if ($field instanceof FileField) {
                    return self::ENC_TYPE_MULTIPART;
                }
            }
        }

        return self::ENC_TYPE_URLENCODED;
    }

    /**
     * Sets the form encoding type. The most common encoding types are defined
     * in {@link ENC_TYPE_URLENCODED} and {@link ENC_TYPE_MULTIPART}.
     *
     * @param string $encType
     * @return $this
     */
    public function setEncType($encType)
    {
        $this->encType = $encType;
        return $this;
    }

    /**
     * Returns the real HTTP method for the form:
     * GET, POST, PUT, DELETE or HEAD.
     * As most browsers only support GET and POST in
     * form submissions, all other HTTP methods are
     * added as a hidden field "_method" that
     * gets evaluated in {@link Director::direct()}.
     * See {@link FormMethod()} to get a HTTP method
     * for safe insertion into a <form> tag.
     *
     * @return string HTTP method
     */
    public function FormHttpMethod()
    {
        return $this->formMethod;
    }

    /**
     * Returns the form method to be used in the <form> tag.
     * See {@link FormHttpMethod()} to get the "real" method.
     *
     * @return string Form HTTP method restricted to 'GET' or 'POST'
     */
    public function FormMethod()
    {
        if (in_array($this->formMethod, array('GET','POST'))) {
            return $this->formMethod;
        } else {
            return 'POST';
        }
    }

    /**
     * Set the form method: GET, POST, PUT, DELETE.
     *
     * @param string $method
     * @param bool $strict If non-null, pass value to {@link setStrictFormMethodCheck()}.
     * @return $this
     */
    public function setFormMethod($method, $strict = null)
    {
        $this->formMethod = strtoupper($method);
        if ($strict !== null) {
            $this->setStrictFormMethodCheck($strict);
        }
        return $this;
    }

    /**
     * If set to true, enforce the matching of the form method.
     *
     * This will mean two things:
     *  - GET vars will be ignored by a POST form, and vice versa
     *  - A submission where the HTTP method used doesn't match the form will return a 400 error.
     *
     * If set to false (the default), then the form method is only used to construct the default
     * form.
     *
     * @param $bool boolean
     * @return $this
     */
    public function setStrictFormMethodCheck($bool)
    {
        $this->strictFormMethodCheck = (bool)$bool;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getStrictFormMethodCheck()
    {
        return $this->strictFormMethodCheck;
    }

    /**
     * Return the form's action attribute.
     * This is build by adding an executeForm get variable to the parent controller's Link() value
     *
     * @return string
     */
    public function FormAction()
    {
        if ($this->formActionPath) {
            return $this->formActionPath;
        } elseif ($this->controller->hasMethod("FormObjectLink")) {
            return $this->controller->FormObjectLink($this->name);
        } else {
            return Controller::join_links($this->controller->Link(), $this->name);
        }
    }

    /**
     * Set the form action attribute to a custom URL.
     *
     * Note: For "normal" forms, you shouldn't need to use this method.  It is
     * recommended only for situations where you have two relatively distinct
     * parts of the system trying to communicate via a form post.
     *
     * @param string $path
     * @return $this
     */
    public function setFormAction($path)
    {
        $this->formActionPath = $path;

        return $this;
    }

    /**
     * Returns the name of the form.
     *
     * @return string
     */
    public function FormName()
    {
        return $this->getTemplateHelper()->generateFormID($this);
    }

    /**
     * Set the HTML ID attribute of the form.
     *
     * @param string $id
     * @return $this
     */
    public function setHTMLID($id)
    {
        $this->htmlID = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getHTMLID()
    {
        return $this->htmlID;
    }

    /**
     * Get the controller.
     *
     * @return Controller
     */
    public function getController()
    {
        return $this->controller;
    }

    /**
     * Set the controller.
     *
     * @param Controller $controller
     * @return Form
     */
    public function setController($controller)
    {
        $this->controller = $controller;

        return $this;
    }

    /**
     * Get the name of the form.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the name of the form.
     *
     * @param string $name
     * @return Form
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Returns an object where there is a method with the same name as each data
     * field on the form.
     *
     * That method will return the field itself.
     *
     * It means that you can execute $firstName = $form->FieldMap()->FirstName()
     */
    public function FieldMap()
    {
        return new Form_FieldMap($this);
    }

    /**
     * Set a message to the session, for display next time this form is shown.
     *
     * @param string $message the text of the message
     * @param string $type Should be set to good, bad, or warning.
     * @param string|bool $cast Cast type; One of the CAST_ constant definitions.
     * Bool values will be treated as plain text flag.
     */
    public function sessionMessage($message, $type = ValidationResult::TYPE_ERROR, $cast = ValidationResult::CAST_TEXT)
    {
        $this->setMessage($message, $type, $cast);
        $result = $this->getSessionValidationResult() ?: ValidationResult::create();
        $result->addMessage($message, $type, null, $cast);
        $this->setSessionValidationResult($result);
    }

    /**
     * Set an error to the session, for display next time this form is shown.
     *
     * @param string $message the text of the message
     * @param string $type Should be set to good, bad, or warning.
     * @param string|bool $cast Cast type; One of the CAST_ constant definitions.
     * Bool values will be treated as plain text flag.
     */
    public function sessionError($message, $type = ValidationResult::TYPE_ERROR, $cast = ValidationResult::CAST_TEXT)
    {
        $this->setMessage($message, $type, $cast);
        $result = $this->getSessionValidationResult() ?: ValidationResult::create();
        $result->addError($message, $type, null, $cast);
        $this->setSessionValidationResult($result);
    }

    /**
     * Returns the DataObject that has given this form its data
     * through {@link loadDataFrom()}.
     *
     * @return DataObject
     */
    public function getRecord()
    {
        return $this->record;
    }

    /**
     * Get the legend value to be inserted into the
     * <legend> element in Form.ss
     *
     * @return string
     */
    public function getLegend()
    {
        return $this->legend;
    }

    /**
     * Processing that occurs before a form is executed.
     *
     * This includes form validation, if it fails, we throw a ValidationException
     *
     * This includes form validation, if it fails, we redirect back
     * to the form with appropriate error messages.
     * Always return true if the current form action is exempt from validation
     *
     * Triggered through {@link httpSubmission()}.
     *
     *
     * Note that CSRF protection takes place in {@link httpSubmission()},
     * if it fails the form data will never reach this method.
     *
     * @return ValidationResult
     */
    public function validationResult()
    {
        // Opportunity to invalidate via validator
        $action = $this->buttonClicked();
        if ($action && $this->actionIsValidationExempt($action)) {
            return ValidationResult::create();
        }

        // Invoke validator
        if ($this->validator) {
            $result = $this->validator->validate();
            $this->loadMessagesFrom($result);
            return $result;
        }

        // Successful result
        return ValidationResult::create();
    }

    const MERGE_DEFAULT = 0;
    const MERGE_CLEAR_MISSING = 1;
    const MERGE_IGNORE_FALSEISH = 2;

    /**
     * Load data from the given DataObject or array.
     *
     * It will call $object->MyField to get the value of MyField.
     * If you passed an array, it will call $object[MyField].
     * Doesn't save into dataless FormFields ({@link DatalessField}),
     * as determined by {@link FieldList->dataFields()}.
     *
     * By default, if a field isn't set (as determined by isset()),
     * its value will not be saved to the field, retaining
     * potential existing values.
     *
     * Passed data should not be escaped, and is saved to the FormField instances unescaped.
     * Escaping happens automatically on saving the data through {@link saveInto()}.
     *
     * Escaping happens automatically on saving the data through
     * {@link saveInto()}.
     *
     * @uses FieldList->dataFields()
     * @uses FormField->setValue()
     *
     * @param array|DataObject $data
     * @param int $mergeStrategy
     *  For every field, {@link $data} is interrogated whether it contains a relevant property/key, and
     *  what that property/key's value is.
     *
     *  By default, if {@link $data} does contain a property/key, the fields value is always replaced by {@link $data}'s
     *  value, even if that value is null/false/etc. Fields which don't match any property/key in {@link $data} are
     *  "left alone", meaning they retain any previous value.
     *
     *  You can pass a bitmask here to change this behaviour.
     *
     *  Passing CLEAR_MISSING means that any fields that don't match any property/key in
     *  {@link $data} are cleared.
     *
     *  Passing IGNORE_FALSEISH means that any false-ish value in {@link $data} won't replace
     *  a field's value.
     *
     *  For backwards compatibility reasons, this parameter can also be set to === true, which is the same as passing
     *  CLEAR_MISSING
     *
     * @param array $fieldList An optional list of fields to process.  This can be useful when you have a
     * form that has some fields that save to one object, and some that save to another.
     * @return $this
     */
    public function loadDataFrom($data, $mergeStrategy = 0, $fieldList = null)
    {
        if (!is_object($data) && !is_array($data)) {
            user_error("Form::loadDataFrom() not passed an array or an object", E_USER_WARNING);
            return $this;
        }

        // Handle the backwards compatible case of passing "true" as the second argument
        if ($mergeStrategy === true) {
            $mergeStrategy = self::MERGE_CLEAR_MISSING;
        } elseif ($mergeStrategy === false) {
            $mergeStrategy = 0;
        }

        // if an object is passed, save it for historical reference through {@link getRecord()}
        if (is_object($data)) {
            $this->record = $data;
        }

        // dont include fields without data
        $dataFields = $this->Fields()->dataFields();
        if (!$dataFields) {
            return $this;
        }

        /** @var FormField $field */
        foreach ($dataFields as $field) {
            $name = $field->getName();

            // Skip fields that have been excluded
            if ($fieldList && !in_array($name, $fieldList)) {
                continue;
            }

            // First check looks for (fieldname)_unchanged, an indicator that we shouldn't overwrite the field value
            if (is_array($data) && isset($data[$name . '_unchanged'])) {
                continue;
            }

            // Does this property exist on $data?
            $exists = false;
            // The value from $data for this field
            $val = null;

            if (is_object($data)) {
                $exists = (
                isset($data->$name) ||
                $data->hasMethod($name) ||
                ($data->hasMethod('hasField') && $data->hasField($name))
                    );

                if ($exists) {
                    $val = $data->__get($name);
                }
            } elseif (is_array($data)) {
                if (array_key_exists($name, $data)) {
                    $exists = true;
                    $val = $data[$name];
                } // If field is in array-notation we need to access nested data
                elseif (strpos($name, '[')) {
                    // First encode data using PHP's method of converting nested arrays to form data
                    $flatData = urldecode(http_build_query($data));
                    // Then pull the value out from that flattened string
                    preg_match('/' . addcslashes($name, '[]') . '=([^&]*)/', $flatData, $matches);

                    if (isset($matches[1])) {
                        $exists = true;
                        $val = $matches[1];
                    }
                }
            }

            // save to the field if either a value is given, or loading of blank/undefined values is forced
            if ($exists) {
                if ($val != false || ($mergeStrategy & self::MERGE_IGNORE_FALSEISH) != self::MERGE_IGNORE_FALSEISH) {
                    // pass original data as well so composite fields can act on the additional information
                    $field->setValue($val, $data);
                }
            } elseif (($mergeStrategy & self::MERGE_CLEAR_MISSING) == self::MERGE_CLEAR_MISSING) {
                $field->setValue($val, $data);
            }
        }
        return $this;
    }

    /**
     * Save the contents of this form into the given data object.
     * It will make use of setCastedField() to do this.
     *
     * @param DataObjectInterface $dataObject The object to save data into
     * @param FieldList $fieldList An optional list of fields to process.  This can be useful when you have a
     * form that has some fields that save to one object, and some that save to another.
     */
    public function saveInto(DataObjectInterface $dataObject, $fieldList = null)
    {
        $dataFields = $this->fields->saveableFields();
        $lastField = null;
        if ($dataFields) {
            foreach ($dataFields as $field) {
            // Skip fields that have been excluded
                if ($fieldList && is_array($fieldList) && !in_array($field->getName(), $fieldList)) {
                    continue;
                }

                $saveMethod = "save{$field->getName()}";
                if ($field->getName() == "ClassName") {
                    $lastField = $field;
                } elseif ($dataObject->hasMethod($saveMethod)) {
                    $dataObject->$saveMethod($field->dataValue());
                } elseif ($field->getName() !== "ID") {
                    $field->saveInto($dataObject);
                }
            }
        }
        if ($lastField) {
            $lastField->saveInto($dataObject);
        }
    }

    /**
     * Get the submitted data from this form through
     * {@link FieldList->dataFields()}, which filters out
     * any form-specific data like form-actions.
     * Calls {@link FormField->dataValue()} on each field,
     * which returns a value suitable for insertion into a DataObject
     * property.
     *
     * @return array
     */
    public function getData()
    {
        $dataFields = $this->fields->dataFields();
        $data = array();

        if ($dataFields) {
            foreach ($dataFields as $field) {
                if ($field->getName()) {
                    $data[$field->getName()] = $field->dataValue();
                }
            }
        }

        return $data;
    }

    /**
     * Return a rendered version of this form.
     *
     * This is returned when you access a form as $FormObject rather
     * than <% with FormObject %>
     *
     * @return DBHTMLText
     */
    public function forTemplate()
    {
        $return = $this->renderWith($this->getTemplates());

        // Now that we're rendered, clear message
        $this->clearMessage();

        return $return;
    }

    /**
     * Return a rendered version of this form, suitable for ajax post-back.
     *
     * It triggers slightly different behaviour, such as disabling the rewriting
     * of # links.
     *
     * @return DBHTMLText
     */
    public function forAjaxTemplate()
    {
        $view = new SSViewer($this->getTemplates());

        $return = $view->dontRewriteHashlinks()->process($this);

        // Now that we're rendered, clear message
        $this->clearMessage();

        return $return;
    }

    /**
     * Returns an HTML rendition of this form, without the <form> tag itself.
     *
     * Attaches 3 extra hidden files, _form_action, _form_name, _form_method,
     * and _form_enctype.  These are the attributes of the form.  These fields
     * can be used to send the form to Ajax.
     *
     * @deprecated 5.0
     * @return string
     */
    public function formHtmlContent()
    {
        Deprecation::notice('5.0');
        $this->IncludeFormTag = false;
        $content = $this->forTemplate();
        $this->IncludeFormTag = true;

        $content .= "<input type=\"hidden\" name=\"_form_action\" id=\"" . $this->FormName . "_form_action\""
            . " value=\"" . $this->FormAction() . "\" />\n";
        $content .= "<input type=\"hidden\" name=\"_form_name\" value=\"" . $this->FormName() . "\" />\n";
        $content .= "<input type=\"hidden\" name=\"_form_method\" value=\"" . $this->FormMethod() . "\" />\n";
        $content .= "<input type=\"hidden\" name=\"_form_enctype\" value=\"" . $this->getEncType() . "\" />\n";

        return $content;
    }

    /**
     * Render this form using the given template, and return the result as a string
     * You can pass either an SSViewer or a template name
     * @param string|array $template
     * @return DBHTMLText
     */
    public function renderWithoutActionButton($template)
    {
        $custom = $this->customise(array(
            "Actions" => "",
        ));

        if (is_string($template)) {
            $template = new SSViewer($template);
        }

        return $template->process($custom);
    }


    /**
     * Sets the button that was clicked.  This should only be called by the Controller.
     *
     * @param callable $funcName The name of the action method that will be called.
     * @return $this
     */
    public function setButtonClicked($funcName)
    {
        $this->buttonClickedFunc = $funcName;

        return $this;
    }

    /**
     * @return FormAction
     */
    public function buttonClicked()
    {
        $actions = $this->getAllActions();
        foreach ($actions as $action) {
            if ($this->buttonClickedFunc === $action->actionName()) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Get a list of all actions, including those in the main "fields" FieldList
     *
     * @return array
     */
    protected function getAllActions()
    {
        $fields = $this->fields->dataFields() ?: array();
        $actions = $this->actions->dataFields() ?: array();

        $fieldsAndActions = array_merge($fields, $actions);
        $actions = array_filter($fieldsAndActions, function ($fieldOrAction) {
            return $fieldOrAction instanceof FormAction;
        });

        return $actions;
    }

    /**
     * Return the default button that should be clicked when another one isn't
     * available.
     *
     * @return FormAction
     */
    public function defaultAction()
    {
        if ($this->hasDefaultAction && $this->actions) {
            return $this->actions->first();
        }
        return null;
    }

    /**
     * Disable the default button.
     *
     * Ordinarily, when a form is processed and no action_XXX button is
     * available, then the first button in the actions list will be pressed.
     * However, if this is "delete", for example, this isn't such a good idea.
     *
     * @return Form
     */
    public function disableDefaultAction()
    {
        $this->hasDefaultAction = false;

        return $this;
    }

    /**
     * Disable the requirement of a security token on this form instance. This
     * security protects against CSRF attacks, but you should disable this if
     * you don't want to tie a form to a session - eg a search form.
     *
     * Check for token state with {@link getSecurityToken()} and
     * {@link SecurityToken->isEnabled()}.
     *
     * @return Form
     */
    public function disableSecurityToken()
    {
        $this->securityToken = new NullSecurityToken();

        return $this;
    }

    /**
     * Enable {@link SecurityToken} protection for this form instance.
     *
     * Check for token state with {@link getSecurityToken()} and
     * {@link SecurityToken->isEnabled()}.
     *
     * @return Form
     */
    public function enableSecurityToken()
    {
        $this->securityToken = new SecurityToken();

        return $this;
    }

    /**
     * Returns the security token for this form (if any exists).
     *
     * Doesn't check for {@link securityTokenEnabled()}.
     *
     * Use {@link SecurityToken::inst()} to get a global token.
     *
     * @return SecurityToken|null
     */
    public function getSecurityToken()
    {
        return $this->securityToken;
    }

    /**
     * Compiles all CSS-classes.
     *
     * @return string
     */
    public function extraClass()
    {
        return implode(array_unique($this->extraClasses), ' ');
    }

    /**
     * Add a CSS-class to the form-container. If needed, multiple classes can
     * be added by delimiting a string with spaces.
     *
     * @param string $class A string containing a classname or several class
     *              names delimited by a single space.
     * @return $this
     */
    public function addExtraClass($class)
    {
        //split at white space
        $classes = preg_split('/\s+/', $class);
        foreach ($classes as $class) {
            //add classes one by one
            $this->extraClasses[$class] = $class;
        }
        return $this;
    }

    /**
     * Remove a CSS-class from the form-container. Multiple class names can
     * be passed through as a space delimited string
     *
     * @param string $class
     * @return $this
     */
    public function removeExtraClass($class)
    {
        //split at white space
        $classes = preg_split('/\s+/', $class);
        foreach ($classes as $class) {
            //unset one by one
            unset($this->extraClasses[$class]);
        }
        return $this;
    }

    public function debug()
    {
        $result = "<h3>$this->class</h3><ul>";
        foreach ($this->fields as $field) {
            $result .= "<li>$field" . $field->debug() . "</li>";
        }
        $result .= "</ul>";

        if ($this->validator) {
            /** @skipUpgrade */
            $result .= '<h3>'._t('Form.VALIDATOR', 'Validator').'</h3>' . $this->validator->debug();
        }

        return $result;
    }


    /////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // TESTING HELPERS
    /////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * Test a submission of this form.
     * @param string $action
     * @param array $data
     * @return HTTPResponse the response object that the handling controller produces.  You can interrogate this in
     * your unit test.
     * @throws HTTPResponse_Exception
     */
    public function testSubmission($action, $data)
    {
        $data['action_' . $action] = true;

        return Director::test($this->FormAction(), $data, Controller::curr()->getSession());
    }

    /**
     * Test an ajax submission of this form.
     *
     * @param string $action
     * @param array $data
     * @return HTTPResponse the response object that the handling controller produces.  You can interrogate this in
     * your unit test.
     */
    public function testAjaxSubmission($action, $data)
    {
        $data['ajax'] = 1;
        return $this->testSubmission($action, $data);
    }
}
