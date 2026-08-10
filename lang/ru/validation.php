<?php

// Russian translations for Laravel's built-in validation messages. Without
// this file the app silently falls back to English (see APP_FALLBACK_LOCALE)
// even though APP_LOCALE=ru - every Validator::make()->validate() call in the
// codebase (AI response schemas included) was leaking raw English messages
// into Russian-facing Telegram output whenever validation failed.

return [

    'accepted' => 'Поле «:attribute» должно быть принято.',
    'accepted_if' => 'Поле «:attribute» должно быть принято, когда «:other» равно «:value».',
    'active_url' => 'Поле «:attribute» должно быть корректным URL-адресом.',
    'after' => 'Значение поля «:attribute» должно быть датой позже «:date».',
    'after_or_equal' => 'Значение поля «:attribute» должно быть датой не раньше «:date».',
    'alpha' => 'Поле «:attribute» может содержать только буквы.',
    'alpha_dash' => 'Поле «:attribute» может содержать только буквы, цифры, дефисы и подчёркивания.',
    'alpha_num' => 'Поле «:attribute» может содержать только буквы и цифры.',
    'array' => 'Поле «:attribute» должно быть массивом.',
    'ascii' => 'Поле «:attribute» может содержать только однобайтовые буквы, цифры и символы.',
    'before' => 'Значение поля «:attribute» должно быть датой раньше «:date».',
    'before_or_equal' => 'Значение поля «:attribute» должно быть датой не позже «:date».',
    'between' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть между :min и :max.',
        'file' => 'Размер файла в поле «:attribute» должен быть между :min и :max килобайт.',
        'numeric' => 'Значение поля «:attribute» должно быть между :min и :max.',
        'string' => 'Длина поля «:attribute» должна быть между :min и :max символов.',
    ],
    'boolean' => 'Поле «:attribute» должно быть true или false.',
    'confirmed' => 'Подтверждение поля «:attribute» не совпадает.',
    'contains' => 'В поле «:attribute» отсутствует обязательное значение.',
    'current_password' => 'Неверный пароль.',
    'date' => 'Поле «:attribute» не является корректной датой.',
    'date_equals' => 'Значение поля «:attribute» должно быть датой, равной «:date».',
    'date_format' => 'Поле «:attribute» не соответствует формату «:format».',
    'decimal' => 'Поле «:attribute» должно содержать :decimal знаков после запятой.',
    'declined' => 'Поле «:attribute» должно быть отклонено.',
    'declined_if' => 'Поле «:attribute» должно быть отклонено, когда «:other» равно «:value».',
    'different' => 'Поля «:attribute» и «:other» должны различаться.',
    'digits' => 'Поле «:attribute» должно содержать :digits цифр.',
    'digits_between' => 'Поле «:attribute» должно содержать от :min до :max цифр.',
    'dimensions' => 'Некорректные размеры изображения в поле «:attribute».',
    'distinct' => 'Значение поля «:attribute» повторяется.',
    'doesnt_end_with' => 'Поле «:attribute» не должно заканчиваться одним из следующих значений: :values.',
    'doesnt_start_with' => 'Поле «:attribute» не должно начинаться с одного из следующих значений: :values.',
    'email' => 'Поле «:attribute» должно быть корректным email-адресом.',
    'ends_with' => 'Поле «:attribute» должно заканчиваться одним из следующих значений: :values.',
    'enum' => 'Выбранное значение поля «:attribute» некорректно.',
    'exists' => 'Выбранное значение поля «:attribute» некорректно.',
    'extensions' => 'Поле «:attribute» должно иметь одно из следующих расширений: :values.',
    'file' => 'Поле «:attribute» должно быть файлом.',
    'filled' => 'Поле «:attribute» обязательно для заполнения.',
    'gt' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть больше :value.',
        'file' => 'Размер файла в поле «:attribute» должен быть больше :value килобайт.',
        'numeric' => 'Значение поля «:attribute» должно быть больше :value.',
        'string' => 'Длина поля «:attribute» должна быть больше :value символов.',
    ],
    'gte' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть :value или больше.',
        'file' => 'Размер файла в поле «:attribute» должен быть :value килобайт или больше.',
        'numeric' => 'Значение поля «:attribute» должно быть :value или больше.',
        'string' => 'Длина поля «:attribute» должна быть :value символов или больше.',
    ],
    'hex_color' => 'Поле «:attribute» должно быть корректным цветом в формате HEX.',
    'image' => 'Поле «:attribute» должно быть изображением.',
    'in' => 'Выбранное значение поля «:attribute» некорректно.',
    'in_array' => 'Поле «:attribute» должно присутствовать в «:other».',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'ip' => 'Поле «:attribute» должно быть корректным IP-адресом.',
    'ipv4' => 'Поле «:attribute» должно быть корректным IPv4-адресом.',
    'ipv6' => 'Поле «:attribute» должно быть корректным IPv6-адресом.',
    'json' => 'Поле «:attribute» должно быть корректной JSON-строкой.',
    'list' => 'Поле «:attribute» должно быть списком.',
    'lowercase' => 'Поле «:attribute» должно быть в нижнем регистре.',
    'lt' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть меньше :value.',
        'file' => 'Размер файла в поле «:attribute» должен быть меньше :value килобайт.',
        'numeric' => 'Значение поля «:attribute» должно быть меньше :value.',
        'string' => 'Длина поля «:attribute» должна быть меньше :value символов.',
    ],
    'lte' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть :value или меньше.',
        'file' => 'Размер файла в поле «:attribute» должен быть :value килобайт или меньше.',
        'numeric' => 'Значение поля «:attribute» должно быть :value или меньше.',
        'string' => 'Длина поля «:attribute» должна быть :value символов или меньше.',
    ],
    'mac_address' => 'Поле «:attribute» должно быть корректным MAC-адресом.',
    'max' => [
        'array' => 'Количество элементов в поле «:attribute» не должно превышать :max.',
        'file' => 'Размер файла в поле «:attribute» не должен превышать :max килобайт.',
        'numeric' => 'Значение поля «:attribute» не должно превышать :max.',
        'string' => 'Длина поля «:attribute» не должна превышать :max символов.',
    ],
    'max_digits' => 'Поле «:attribute» не должно содержать больше :max цифр.',
    'mimes' => 'Поле «:attribute» должно быть файлом одного из типов: :values.',
    'mimetypes' => 'Поле «:attribute» должно быть файлом одного из типов: :values.',
    'min' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть не менее :min.',
        'file' => 'Размер файла в поле «:attribute» должен быть не менее :min килобайт.',
        'numeric' => 'Значение поля «:attribute» должно быть не менее :min.',
        'string' => 'Длина поля «:attribute» должна быть не менее :min символов.',
    ],
    'min_digits' => 'Поле «:attribute» должно содержать не менее :min цифр.',
    'missing' => 'Поле «:attribute» должно отсутствовать.',
    'missing_if' => 'Поле «:attribute» должно отсутствовать, когда «:other» равно «:value».',
    'missing_unless' => 'Поле «:attribute» должно отсутствовать, если только «:other» не равно «:value».',
    'missing_with' => 'Поле «:attribute» должно отсутствовать, когда присутствует «:values».',
    'missing_with_all' => 'Поле «:attribute» должно отсутствовать, когда присутствуют «:values».',
    'multiple_of' => 'Значение поля «:attribute» должно быть кратно :value.',
    'not_in' => 'Выбранное значение поля «:attribute» некорректно.',
    'not_regex' => 'Формат поля «:attribute» некорректен.',
    'numeric' => 'Поле «:attribute» должно быть числом.',
    'password' => [
        'letters' => 'Поле «:attribute» должно содержать хотя бы одну букву.',
        'mixed' => 'Поле «:attribute» должно содержать хотя бы одну заглавную и одну строчную букву.',
        'numbers' => 'Поле «:attribute» должно содержать хотя бы одну цифру.',
        'symbols' => 'Поле «:attribute» должно содержать хотя бы один символ.',
        'uncompromised' => 'Значение поля «:attribute» встречалось в утечках данных. Выберите другое значение.',
    ],
    'present' => 'Поле «:attribute» должно присутствовать.',
    'present_if' => 'Поле «:attribute» должно присутствовать, когда «:other» равно «:value».',
    'present_unless' => 'Поле «:attribute» должно присутствовать, если только «:other» не равно «:value».',
    'present_with' => 'Поле «:attribute» должно присутствовать, когда присутствует «:values».',
    'present_with_all' => 'Поле «:attribute» должно присутствовать, когда присутствуют «:values».',
    'prohibited' => 'Поле «:attribute» запрещено.',
    'prohibited_if' => 'Поле «:attribute» запрещено, когда «:other» равно «:value».',
    'prohibited_unless' => 'Поле «:attribute» запрещено, если только «:other» не входит в «:values».',
    'prohibits' => 'Поле «:attribute» запрещает присутствие «:other».',
    'regex' => 'Формат поля «:attribute» некорректен.',
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'required_array_keys' => 'Поле «:attribute» должно содержать записи: :values.',
    'required_if' => 'Поле «:attribute» обязательно, когда «:other» равно «:value».',
    'required_if_accepted' => 'Поле «:attribute» обязательно, когда «:other» принято.',
    'required_if_declined' => 'Поле «:attribute» обязательно, когда «:other» отклонено.',
    'required_unless' => 'Поле «:attribute» обязательно, если только «:other» не входит в «:values».',
    'required_with' => 'Поле «:attribute» обязательно, когда присутствует «:values».',
    'required_with_all' => 'Поле «:attribute» обязательно, когда присутствуют «:values».',
    'required_without' => 'Поле «:attribute» обязательно, когда отсутствует «:values».',
    'required_without_all' => 'Поле «:attribute» обязательно, когда отсутствуют все «:values».',
    'same' => 'Поля «:attribute» и «:other» должны совпадать.',
    'size' => [
        'array' => 'Поле «:attribute» должно содержать :size элементов.',
        'file' => 'Размер файла в поле «:attribute» должен быть :size килобайт.',
        'numeric' => 'Значение поля «:attribute» должно быть :size.',
        'string' => 'Длина поля «:attribute» должна быть :size символов.',
    ],
    'starts_with' => 'Поле «:attribute» должно начинаться с одного из следующих значений: :values.',
    'string' => 'Поле «:attribute» должно быть строкой.',
    'timezone' => 'Поле «:attribute» должно быть корректным часовым поясом.',
    'unique' => 'Такое значение поля «:attribute» уже существует.',
    'uploaded' => 'Не удалось загрузить файл поля «:attribute».',
    'uppercase' => 'Поле «:attribute» должно быть в верхнем регистре.',
    'url' => 'Поле «:attribute» должно быть корректным URL-адресом.',
    'ulid' => 'Поле «:attribute» должно быть корректным ULID.',
    'uuid' => 'Поле «:attribute» должно быть корректным UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom validation attributes
    |--------------------------------------------------------------------------
    |
    | Field names left untranslated fall back to the raw snake_case/dot
    | attribute name (e.g. "expected_count_evidence"), which is acceptable
    | here since most validated fields are internal AI-response keys, not
    | user-facing form labels.
    |
    */

    'attributes' => [],

];
