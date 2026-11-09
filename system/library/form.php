<?php
declare(strict_types=1);
namespace System\Library;

use System\Engine\Registry;
use System\Library\View;
use System\Library\Request;

class Form
{
    protected array      $values    = [];
    protected array      $errors    = [];
    protected ?string    $formError = null;
    protected View       $view;
    protected Request    $request;

    public function __construct(protected Registry $registry)
    {
        $this->view = $registry->get('view');
        $this->request = $registry->get('request');
    }

    // =========================================================================
    // Form Wrapper
    // =========================================================================
    public function open(array $options = []): string
    {
        $action = $options['action'] ?? '';
        $method = strtoupper($options['method'] ?? 'POST');
        $class  = $options['class']  ?? '';

        $html = '<form action="' . $this->view->e($action) . '"'
              . ' method="' . $this->view->e($method === 'GET' ? 'get' : 'post') . '"';

        if ($class !== '') {
            $html .= ' class="' . $this->view->e($class) . '"';
        }
        if (isset($options['autocomplete'])) {
            $html .= ' autocomplete="' . $this->view->e($options['autocomplete']) . '"';
        }
        if (!empty($options['files'])) {
            $html .= ' enctype="multipart/form-data"';
        }
        foreach ($options['attrs'] ?? [] as $name => $value) {
            $html .= ' ' . $name . '="' . $this->view->e((string) $value) . '"';
        }
        $html .= '>';

        if ($method !== 'GET') {
            $html .= $this->request->csrfField();
            if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
                $html .= '<input type="hidden" name="_method" value="' . $method . '">';
            }
        }
        if ($this->formError !== null) {
            $html .= $this->alert(['type' => 'danger', 'message' => $this->formError]);
        }

        return $html;
    }

    public function close(): string
    {
        return '</form>';
    }

    // =========================================================================
    // Field Dispatcher — NO auto-map, explicit only
    // =========================================================================
    public function field(array $config): string
    {
        $type = $config['type'] ?? 'text';
        $name = $config['name'] ?? '';

        $noNameTypes  = ['row', 'col', 'html', 'submit', 'button'];
        $isStructural = in_array($type, $noNameTypes, true);

        if (!$isStructural && $name === '') {
            throw new \InvalidArgumentException('Form field requires a "name" key.');
        }

        if (!$isStructural && !array_key_exists('autocomplete', $config)) {
            $config['autocomplete'] = match ($type) {
                'checkbox', 'radio', 'file' => 'off',
                default => match ($name) {
                    'username', 'user', 'email' => $name,
                    'password' => 'current-password',
                    default => 'off',
                },
            };
        }

        return match ($type) {
            'text', 'email', 'password', 'number', 'tel', 'url', 'datetime-local', 'date', 'time', 'month', 'week', 'range', 'color'
                => $this->input($type, $config),
            'hidden'     => $this->hidden($config),
            'textarea'   => $this->textarea($config),
            'select'     => $this->select($config),
            'checkbox'   => $this->checkbox($config),
            'radio'      => $this->radio($config),
            'radioGroup' => $this->radioGroup($config),
            'file'       => $this->file($config),
            'submit'     => $this->submit($config),
            'button'     => $this->button($config),
            'row'        => $this->row($config),
            'col'        => $this->col($config),
            'html'       => (string) ($config['html'] ?? ''),
            default      => throw new \InvalidArgumentException("Unknown form field type: '{$type}'."),
        };
    }

    public function fields(array $fields): string
    {
        $html = '';
        foreach ($fields as $config) {
            $html .= $this->field($config);
        }
        return $html;
    }

    // =========================================================================
    // Field Types
    // =========================================================================
    protected function input(string $type, array $c): string
    {
        $name  = $c['name'];
        $id    = $c['id'] ?? 'field-' . $name;
        $value = $c['value'] ?? $this->values[$name] ?? '';
        $auto  = $this->autoAttr($c);
        $attrs = $this->buildAttrs($c, ['type', 'name', 'label', 'value', 'help', 'autocomplete']);

        $html = $this->fieldOpen($c);
        $html .= '<input'
               . ' type="' . $this->view->e($type) . '"'
               . ' id="' . $this->view->e($id) . '"'
               . ' name="' . $this->view->e($name) . '"'
               . ' value="' . $this->view->e((string) $value) . '"'
               . ' class="form-control' . $this->invalidClass($name) . '"'
               . $auto
               . $attrs
               . '>';
        $html .= $this->fieldClose($c);
        return $html;
    }

    public function hidden(array $c): string
    {
        $name  = $c['name'];
        $value = $c['value'] ?? $this->values[$name] ?? '';
        return '<input type="hidden"'
              . ' name="' . $this->view->e($name) . '"'
              . ' value="' . $this->view->e((string) $value) . '">';
    }

    protected function textarea(array $c): string
    {
        $name   = $c['name'];
        $id     = $c['id'] ?? 'field-' . $name;
        $value  = $c['value'] ?? $this->values[$name] ?? '';
        $rows   = (int) ($c['rows'] ?? 4);
        $auto   = $this->autoAttr($c);
        $attrs  = $this->buildAttrs($c, ['type', 'name', 'label', 'value', 'rows', 'help', 'autocomplete']);
        $editor = !empty($c['editor']);

        if ($editor) {
            // Load the library once per page.
            static $tinymceLoaded = false;
            if (!$tinymceLoaded) {
                $tinymceLoaded = true;
                registry('view')->block('scripts',
                    "<script src='https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js'></script>"
                );
            }

            $url         = registry('url');
            $uploadUrl   = $this->view->e($url->to('tools/admin/media/upload'));
            $pickerUrl   = $this->view->e($url->to('tools/admin/media/picker'));
            $csrfToken   = $this->request->csrfToken('media');
            $siteRoot = rtrim($this->request->baseUrl(), '/') . '/';

            registry('view')->block('scripts',
                "<script>
                    tinymce.init({
                        selector: '#" . $this->view->e($id) . "',
                        height: 500,
                        menubar: false,
                        plugins: 'lists link code table image',
                        toolbar: 'undo redo | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media | code removeformat',
                        extended_valid_elements: 'iframe[src|width|height|frameborder|allowfullscreen],img[src|alt|width|height]',
                        relative_urls: false,
                        remove_script_host: false,
                        document_base_url: '" . $this->view->e($siteRoot) . "',
                        automatic_uploads: true,
                        images_upload_url: '" . $uploadUrl . "',
                        images_upload_handler: function (blobInfo, progress) {
                            return new Promise(function (resolve, reject) {
                                var xhr = new XMLHttpRequest();
                                xhr.open('POST', '" . $uploadUrl . "');
                                xhr.setRequestHeader('X-CSRF-Token-Media', '" . $csrfToken . "');
                                xhr.setRequestHeader('Accept', 'application/json');

                                xhr.upload.onprogress = function (e) {
                                    if (e.lengthComputable) {
                                        progress(e.loaded / e.total * 100);
                                    }
                                };

                                xhr.onload = function () {
                                    if (xhr.status < 200 || xhr.status >= 300) {
                                        reject('Upload failed (HTTP ' + xhr.status + ')');
                                        return;
                                    }
                                    var json;
                                    try {
                                        json = JSON.parse(xhr.responseText);
                                    } catch (e) {
                                        reject('Invalid JSON from server');
                                        return;
                                    }
                                    if (!json || typeof json.location !== 'string') {
                                        reject(json && json.error ? json.error : 'Invalid response');
                                        return;
                                    }
                                    resolve(json.location);
                                };

                                xhr.onerror = function () {
                                    reject('Network error during upload');
                                };

                                var formData = new FormData();
                                formData.append('file', blobInfo.blob(), blobInfo.filename());
                                xhr.send(formData);
                            });
                        },
                        file_picker_callback: function (callback, value, meta) {
                            if (meta.filetype !== 'image') {
                                return;
                            }

                            var overlay = document.createElement('div');
                            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center;';

                            var box = document.createElement('div');
                            box.style.cssText = 'background:#fff;border-radius:8px;width:min(900px,90vw);max-height:85vh;overflow:auto;padding:20px;';
                            box.innerHTML = '<div style=\"display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;\">'
                                        + '<strong>Media library</strong>'
                                        + '<button type=\"button\" data-close style=\"border:0;background:none;font-size:20px;cursor:pointer;\">&times;</button>'
                                        + '</div>'
                                        + '<div data-grid style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;\">'
                                        + '<p>Loading…</p>'
                                        + '</div>';

                            overlay.appendChild(box);
                            document.body.appendChild(overlay);

                            var grid = box.querySelector('[data-grid]');

                            function close() {
                                overlay.remove();
                            }

                            overlay.addEventListener('click', function (e) {
                                if (e.target === overlay || e.target.hasAttribute('data-close')) {
                                    close();
                                }
                            });

                            fetch('" . $pickerUrl . "?per_page=48')
                                .then(function (r) { return r.json(); })
                                .then(function (data) {
                                    grid.innerHTML = '';

                                    if (!data.items || data.items.length === 0) {
                                        grid.innerHTML = '<p>No media yet. Upload images with the image button.</p>';
                                        return;
                                    }

                                    data.items.forEach(function (item) {
                                        var card = document.createElement('button');
                                        card.type = 'button';
                                        card.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:6px;background:#fff;cursor:pointer;text-align:left;';

                                        var img = document.createElement('img');
                                        img.src = String(item.url || '');
                                        img.alt = '';
                                        img.style.cssText = 'width:100%;height:100px;object-fit:cover;border-radius:4px;display:block;margin-bottom:6px;';

                                        var label = document.createElement('div');
                                        label.style.cssText = 'font-size:12px;color:#666;word-break:break-all;';
                                        label.textContent = String(item.filename || '');

                                        card.appendChild(img);
                                        card.appendChild(label);

                                        card.addEventListener('click', function () {
                                            callback(item.url, { alt: item.filename });
                                            close();
                                        });

                                        grid.appendChild(card);
                                    });
                                })
                                .catch(function () {
                                    grid.innerHTML = '<p>Could not load media. See browser console.</p>';
                                });
                        }
                    });
                </script>"
            );
        }

        $html = $this->fieldOpen($c);
        $html .= '<textarea'
            . ' id="' . $this->view->e($id) . '"'
            . ' name="' . $this->view->e($name) . '"'
            . ' rows="' . $rows . '"'
            . ' class="form-control' . $this->invalidClass($name) . '"'
            . $auto
            . $attrs
            . '>' . $this->view->e((string) $value) . '</textarea>';
        $html .= $this->fieldClose($c);
        return $html;
    }

    public function select(array $c): string
    {
        $name     = $c['name'];
        $id       = $c['id'] ?? 'field-' . $name;
        $options  = $c['options'] ?? [];
        $selected = $c['value'] ?? $this->values[$name] ?? null;
        $multiple = !empty($c['multiple']);
        $auto     = $this->autoAttr($c);
        $attrs    = $this->buildAttrs($c, [
            'type', 'name', 'label', 'value', 'multiple', 'placeholder', 'help', 'options', 'autocomplete',
        ]);

        $html = $this->fieldOpen($c);
        $html .= '<select'
               . ' id="' . $this->view->e($id) . '"'
               . ' name="' . $this->view->e($name) . ($multiple ? '[]' : '') . '"'
               . ($multiple ? ' multiple' : '')
               . ' class="form-select' . $this->invalidClass($name) . '"'
               . $auto
               . $attrs
               . '>';

        if (!empty($c['placeholder'])) {
            $html .= '<option value="">' . $this->view->e($c['placeholder']) . '</option>';
        }
        foreach ($options as $value => $text) {
            $isSelected = $multiple
                ? (is_array($selected) && in_array((string) $value, array_map('strval', $selected), true))
                : ((string) $selected === (string) $value);
            $html .= '<option value="' . $this->view->e((string) $value) . '"'
                  . ($isSelected ? ' selected' : '')
                  . '>' . $this->view->e((string) $text) . '</option>';
        }
        $html .= '</select>';
        $html .= $this->fieldClose($c);
        return $html;
    }

    public function checkbox(array $c): string
    {
        $name    = $c['name'];
        $value   = (string) ($c['value'] ?? '1');
        $checked = $c['checked'] ?? ((string) ($this->values[$name] ?? '') === $value);
        $id      = $c['id'] ?? 'field-' . $name;
        $help    = $c['help'] ?? null;
        $auto    = $this->autoAttr($c);

        $html = '<div class="mb-3 form-check">';
        $html .= '<input type="hidden" name="' . $this->view->e($name) . '" value="0">';
        $html .= '<input type="checkbox"'
               . ' class="form-check-input' . $this->invalidClass($name) . '"'
               . ' id="' . $this->view->e($id) . '"'
               . ' name="' . $this->view->e($name) . '"'
               . ' value="' . $this->view->e($value) . '"'
               . ($checked ? ' checked' : '')
               . $auto
               . '>';
        $html .= '<label class="form-check-label" for="' . $this->view->e($id) . '">'
               . $this->view->e($c['label'] ?? '')
               . '</label>';

        if (isset($this->errors[$name])) {
            $html .= '<div class="invalid-feedback d-block">' . $this->view->e($this->errors[$name]) . '</div>';
        } elseif ($help !== null) {
            $html .= '<div class="form-text">' . $this->view->e($help) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    public function radio(array $c): string
    {
        $name    = $c['name'];
        $value   = (string) ($c['value'] ?? '');
        $checked = $c['checked'] ?? ((string) ($this->values[$name] ?? '') === $value);
        $id      = $c['id'] ?? 'field-' . $name . '-' . preg_replace('/[^a-z0-9]+/i', '-', $value);
        $auto    = $this->autoAttr($c);

        $html = '<div class="form-check">';
        $html .= '<input type="radio"'
            . ' class="form-check-input"'
            . ' id="' . $this->view->e($id) . '"'
            . ' name="' . $this->view->e($name) . '"'
            . ' value="' . $this->view->e($value) . '"'
            . ($checked ? ' checked' : '')
            . $auto
            . '>';
        $html .= '<label class="form-check-label" for="' . $this->view->e($id) . '">'
            . $this->view->e($c['label'] ?? '')
            . '</label>';
        $html .= '</div>';
        return $html;
    }

    public function radioGroup(array $c): string
    {
        $name    = $c['name'];
        $options = $c['options'] ?? [];

        $html = $this->fieldOpen($c);
        foreach ($options as $value => $text) {
            $html .= $this->radio([
                'type'  => 'radio',
                'name'  => $name,
                'label' => $text,
                'value' => (string) $value,
                // id intentionally not passed — each radio generates its own.
            ]);
        }
        $html .= $this->fieldClose(array_merge($c, ['no_help' => true]));
        return $html;
    }

    public function file(array $c): string
    {
        $name  = $c['name'];
        $id    = $c['id'] ?? 'field-' . $name;
        $auto  = $this->autoAttr($c);
        $attrs = $this->buildAttrs($c, ['type', 'name', 'label', 'value', 'help', 'autocomplete']);

        $html = $this->fieldOpen($c);
        $html .= '<input type="file"'
               . ' id="' . $this->view->e($id) . '"'
               . ' name="' . $this->view->e($name) . '"'
               . ' class="form-control' . $this->invalidClass($name) . '"'
               . $auto
               . $attrs
               . '>';
        $html .= $this->fieldClose($c);
        return $html;
    }

    public function submit(array $c): string
    {
        return '<button type="submit" class="' . $this->view->e($c['class'] ?? 'btn btn-primary') . '">'
              . $this->view->e($c['label'] ?? 'Submit')
              . '</button>';
    }

    public function button(array $c): string
    {
        return '<button type="' . $this->view->e($c['btn_type'] ?? 'button') . '" class="'
              . $this->view->e($c['class'] ?? 'btn btn-secondary') . '">'
              . $this->view->e($c['label'] ?? 'Button')
              . '</button>';
    }

    public function row(array $c): string
    {
        return '<div class="' . $this->view->e($c['class'] ?? 'row') . '">'
              . $this->fields($c['fields'] ?? [])
              . '</div>';
    }

    public function col(array $c): string
    {
        return '<div class="' . $this->view->e($c['class'] ?? 'col-md-6') . '">'
              . $this->fields($c['fields'] ?? [])
              . '</div>';
    }

    public function alert(array $c): string
    {
        $type = in_array($c['type'] ?? 'info', ['success','danger','warning','info','primary','secondary'], true)
            ? $c['type'] : 'info';
        return '<div class="alert alert-' . $type . '" role="alert">'
              . $this->view->e($c['message'] ?? '')
              . '</div>';
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    protected function autoAttr(array $c): string
    {
        $value = $c['autocomplete'] ?? '';
        if ($value === '' || $value === null || $value === false) {
            return ''; // skip attribute entirely
        }
        return ' autocomplete="' . $this->view->e($value) . '"';
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    protected function fieldOpen(array $c): string
    {
        $name = $c['name'];
        $id   = $c['id'] ?? 'field-' . $name;
        
        $colClass = $c['col'] ?? 'mb-3';
        if ($colClass === true) {
            $colClass = 'col-md-6 mb-3';
        }
        $html = '<div class="' . $this->view->e($colClass) . '">';
        
        if (!empty($c['label'])) {
            $html .= '<label for="' . $this->view->e($id) . '" class="form-label">'
                   . $this->view->e($c['label'])
                   . (!empty($c['required']) ? ' <span class="text-danger">*</span>' : '')
                   . '</label>';
        }
        return $html;
    }

    protected function fieldClose(array $c): string
    {
        $name = $c['name'];
        $html = '';
        if (isset($this->errors[$name])) {
            $html .= '<div class="invalid-feedback d-block">' . $this->view->e($this->errors[$name]) . '</div>';
        } elseif (!empty($c['help']) && empty($c['no_help'])) {
            $html .= '<div class="form-text">' . $this->view->e($c['help']) . '</div>';
        }
        return $html . '</div>';
    }

    protected function buildAttrs(array $c, array $skip): string
    {
        $skip = array_merge($skip, ['id', 'col', 'required', 'no_help', 'editor', 'rows', 'options']);
        $html = '';
        foreach (['required', 'disabled', 'readonly', 'autofocus', 'multiple', 'novalidate'] as $bool) {
            if (!empty($c[$bool])) $html .= " $bool";
        }
        foreach ($c as $key => $value) {
            if (in_array($key, $skip, true) || is_bool($value) || is_array($value)) continue;
            // Reject obviously non-attribute keys.
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $key)) continue;
            $html .= " $key=\"" . $this->view->e((string)$value) . "\"";
        }
        return $html;
    }

    protected function invalidClass(string $name): string
    {
        return isset($this->errors[$name]) ? ' is-invalid' : '';
    }

    // =========================================================================
    // State
    // =========================================================================
    public function setErrors(array $errors): static
    {
        $this->errors = $errors;
        return $this;
    }

    public function setFormError(?string $message): static
    {
        $this->formError = $message;
        return $this;
    }

    public function setValues(array $values): static
    {
        $this->values = $values;
        return $this;
    }
}