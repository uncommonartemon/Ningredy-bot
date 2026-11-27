import { computed, ref } from 'vue';

const messages = {
    en: {
        searchLabel: 'Search', allCategories: 'All products', navigation: 'Main navigation',
        selectedFilters: 'Selected filters', removeFilter: 'Remove filter', closeFilters: 'Close filters',
        minimumPrice: 'Minimum price', maximumPrice: 'Maximum price', showResults: 'Show',
        updating: 'Updating products…', viewMode: 'Product view', viewProduct: 'View product',
        photoUnavailable: 'Photo unavailable', previousPage: 'Previous', nextPage: 'Next',
        skipToProducts: 'Skip to products', invalidPriceRange: 'The minimum price must not exceed the maximum price.',
        filterError: 'Could not apply filters. Check the values and try again.',
        updatingFilters: 'Updating filter counts…', afterFilterChange: 'After selecting this option',
        filterCountsHint: 'Numbers in brackets show the product count after selecting an option.',
        priceRange: 'Price range', priceOutsideRange: 'The entered limit is outside the available prices. Its exact value is kept.',
        noPricedProducts: 'No listed prices for this selection.', clearPrice: 'Clear price',
        condition: { new: 'New', used: 'Used', refurbished: 'Refurbished', open_box: 'Open box', unknown: 'Condition unspecified' },
        pageTitle: 'Electronics catalog', catalog: 'Electronics catalog', search: 'Search products…',
        filters: 'Filters', filtersActive: 'active', selectedOptions: 'selected', reset: 'Reset', resetFilters: 'Reset filters',
        category: 'Category', price: 'Price', from: 'from', to: 'to', apply: 'Apply', sorting: 'Sorting',
        sortNewest: 'Newest', sortPriceAsc: 'Cheapest', sortPriceDesc: 'Most expensive', sortTitle: 'By title',
        grid: 'Grid', list: 'List', emptyTitle: 'No products found', emptyText: 'Change or reset the filters.',
        perPage: 'per page', breadcrumbs: 'You are here', subcategories: 'Subcategories', moreCount: '{n} more', showLess: 'Show less',
        priceUnavailable: 'Price not available', tech: 'Electronics', pagination: 'Catalog pagination', language: 'Interface language',
        stock: { in_stock: 'In stock', out_of_stock: 'Out of stock', preorder: 'Pre-order', unknown: 'Check availability' },
        facets: { brands: 'Brand', types: 'Type', countries: 'Brand country', colors: 'Color', stock: 'Availability', condition: 'Condition' },
        types: { laptop: 'Laptop', desktop: 'Desktop PC', component: 'Component', other: 'Other electronics' },
        photoNotice: {
            otherConfiguration: 'The photos show another configuration in the same body; this product’s configuration is in its specifications.',
            keyboard: {
                differs: 'The keyboard layout in the photos differs from this product’s.',
                unverified: 'The keyboard layout in the photos could not be checked against this product’s.',
            },
            markings: 'Processor or graphics stickers in the photos may differ from the product configuration.',
        },
        account: {
            partnerLogin: 'Partner sign-in', partners: 'For partners', signInTitle: 'Sign in',
            signInLead: 'Sign in to see the prices of your group.', email: 'Email', password: 'Password', signIn: 'Sign in',
            inviteOnly: 'There is no open sign-up: an account is made through an invitation link. Ask us for yours.',
            wrongCredentials: 'Wrong email or password.', throttled: 'Too many attempts. Try again in a minute.',
            checkField: 'Check this field.', joinTitle: 'Sign up', joinLead: 'Your account will see the prices of this group.',
            name: 'Your name', company: 'Company (optional)', companyLabel: 'Company', phone: 'Phone (optional)',
            passwordConfirm: 'Repeat the password', passwordHint: 'At least 8 characters.',
            passwordMismatch: 'The passwords do not match.', emailTaken: 'This email is already registered. Sign in instead.',
            join: 'Create the account', haveAccount: 'Already registered? Sign in', accountTitle: 'Your account', group: 'Group',
            groupPrices: 'You see the prices of your group.', regularPrices: 'You see the ordinary prices.',
            toCatalog: 'To the catalog', signOut: 'Sign out', yourPrice: 'Your price',
        },
        home: {
            link: 'Home', title: 'Restored and new laptops and parts',
            words: ['Found it', 'cheaper?', 'Compare'], headline: 'Found it cheaper? Compare.',
            lead: 'Restored and new laptops and parts at prices worth seeing. Just take a look at the catalog.',
            manifesto: 'Ningredy restores laptops and sells new ones at prices that are hard to find elsewhere. Each one is checked down to the last part number. Do not take our word for it: open the catalog and compare.',
            ways: {
                restored: { word: 'Restored', text: 'Laptops and devices after restoration: checked, working and clearly cheaper than new.', link: 'See the restored' },
                new: { word: 'New', text: 'New models at prices worth comparing with any shop.', link: 'See the new' },
                parts: { word: 'Parts', text: 'SSDs, memory, processors and more: every item with its exact code and specifications.', link: 'See the parts' },
            },
            searchPlaceholder: 'Model, code or part number', searchHint: 'Press / to search', searchAll: 'Show all results',
            searchNone: 'Nothing found. Try a shorter model name or a code.', searching: 'Searching…',
            statLowest: 'Prices from', statProducts: 'In the catalog', statInStock: 'In stock', statUpdated: 'Updated',
            emptyBench: 'The catalog is being filled. New products appear here as soon as they are confirmed.',
            arrivals: 'Recently added', openCatalog: 'Open the catalog', categories: 'Categories', brands: 'Brands', budgets: 'By budget',
            catalogWord: 'Catalog', justLook: 'Just take a look.', previous: 'Previous products', next: 'Next products',
        },
        attributes: {
            cpu: 'Processor', gpu: 'Graphics card', ram: 'Memory', storage: 'Storage',
            display: 'Display', screen_size: 'Screen size', refresh_rate: 'Refresh rate',
        },
    },
    cs: {
        searchLabel: 'Hledání', allCategories: 'Všechny produkty', navigation: 'Hlavní navigace',
        selectedFilters: 'Vybrané filtry', removeFilter: 'Odebrat filtr', closeFilters: 'Zavřít filtry',
        minimumPrice: 'Minimální cena', maximumPrice: 'Maximální cena', showResults: 'Zobrazit',
        updating: 'Aktualizuji produkty…', viewMode: 'Zobrazení produktů', viewProduct: 'Detail produktu',
        photoUnavailable: 'Fotografie není k dispozici', previousPage: 'Předchozí', nextPage: 'Další',
        skipToProducts: 'Přejít na produkty', invalidPriceRange: 'Minimální cena nesmí být vyšší než maximální.',
        filterError: 'Filtry se nepodařilo použít. Zkontrolujte hodnoty a zkuste to znovu.',
        updatingFilters: 'Přepočítávám filtry…', afterFilterChange: 'Po výběru této možnosti',
        filterCountsHint: 'Čísla v závorkách ukazují počet produktů po výběru možnosti.',
        priceRange: 'Cenové rozpětí', priceOutsideRange: 'Zadaný limit je mimo dostupné ceny. Jeho přesná hodnota zůstává zachována.',
        noPricedProducts: 'Pro tento výběr nejsou uvedeny ceny.', clearPrice: 'Vymazat cenu',
        condition: { new: 'Nové', used: 'Použité', refurbished: 'Repasované', open_box: 'Rozbalené', unknown: 'Stav neuveden' },
        pageTitle: 'Katalog techniky',
        catalog: 'Katalog techniky',
        search: 'Hledat produkty…',
        filters: 'Filtry',
        filtersActive: 'aktivní',
        selectedOptions: 'vybráno',
        reset: 'Vymazat',
        resetFilters: 'Vymazat filtry',
        category: 'Kategorie',
        price: 'Cena',
        from: 'od',
        to: 'do',
        apply: 'Použít',
        sorting: 'Řazení',
        sortNewest: 'Nejnovější',
        sortPriceAsc: 'Nejlevnější',
        sortPriceDesc: 'Nejdražší',
        sortTitle: 'Podle názvu',
        grid: 'Mřížka',
        list: 'Seznam',
        perPage: 'na stránku',
        breadcrumbs: 'Jste zde',
        subcategories: 'Podkategorie',
        moreCount: 'Dalších {n}',
        showLess: 'Zobrazit méně',
        emptyTitle: 'Nebyly nalezeny žádné produkty',
        emptyText: 'Upravte nebo vymažte filtry.',
        priceUnavailable: 'Cena není uvedena',
        tech: 'Technika',
        pagination: 'Stránkování katalogu',
        language: 'Jazyk rozhraní',
        stock: {
            in_stock: 'Skladem',
            out_of_stock: 'Není skladem',
            preorder: 'Předobjednávka',
            unknown: 'Ověřit dostupnost',
        },
        facets: {
            brands: 'Značka', types: 'Typ', countries: 'Země značky', colors: 'Barva', stock: 'Dostupnost', condition: 'Stav',
        },
        types: {
            laptop: 'Notebook', desktop: 'Stolní počítač', component: 'Komponenta', other: 'Ostatní technika',
        },
        photoNotice: {
            otherConfiguration: 'Fotografie zachycují jinou konfiguraci ve stejném provedení; konfigurace tohoto produktu je uvedena v parametrech.',
            keyboard: {
                differs: 'Rozložení klávesnice na fotografiích se liší od rozložení tohoto produktu.',
                unverified: 'Rozložení klávesnice na fotografiích nebylo možné porovnat s rozložením tohoto produktu.',
            },
            markings: 'Nálepky procesoru nebo grafiky na fotografiích se mohou lišit od konfigurace produktu.',
        },
        account: {
            partnerLogin: 'Přihlášení pro partnery', partners: 'Pro partnery', signInTitle: 'Přihlášení',
            signInLead: 'Po přihlášení uvidíte ceny své skupiny.', email: 'E-mail', password: 'Heslo', signIn: 'Přihlásit se',
            inviteOnly: 'Volná registrace tu není: účet vzniká přes zvací odkaz. Požádejte nás o něj.',
            wrongCredentials: 'Nesprávný e-mail nebo heslo.', throttled: 'Příliš mnoho pokusů. Zkuste to za minutu.',
            checkField: 'Zkontrolujte toto pole.', joinTitle: 'Registrace', joinLead: 'Váš účet uvidí ceny této skupiny.',
            name: 'Vaše jméno', company: 'Firma (nepovinné)', companyLabel: 'Firma', phone: 'Telefon (nepovinné)',
            passwordConfirm: 'Heslo znovu', passwordHint: 'Alespoň 8 znaků.',
            passwordMismatch: 'Hesla se neshodují.', emailTaken: 'Tento e-mail je už registrován. Přihlaste se.',
            join: 'Vytvořit účet', haveAccount: 'Už máte účet? Přihlaste se', accountTitle: 'Váš účet', group: 'Skupina',
            groupPrices: 'Vidíte ceny své skupiny.', regularPrices: 'Vidíte běžné ceny.',
            toCatalog: 'Do katalogu', signOut: 'Odhlásit se', yourPrice: 'Vaše cena',
        },
        home: {
            link: 'Úvod', title: 'Repasované i nové notebooky a komponenty',
            words: ['Našli jste', 'levněji?', 'Porovnejte'], headline: 'Našli jste levněji? Porovnejte.',
            lead: 'Repasované i nové notebooky a komponenty za ceny, které stojí za vidění. Stačí nahlédnout do katalogu.',
            manifesto: 'Ningredy repasuje notebooky a prodává nové za ceny, které jinde hledáte těžko. Každý kontrolujeme do posledního part numberu. Nevěřte nám na slovo — otevřete katalog a porovnejte.',
            ways: {
                restored: { word: 'Repasované', text: 'Notebooky a technika po repasi: zkontrolované, funkční a znatelně levnější než nové.', link: 'Zobrazit repasované' },
                new: { word: 'Nové', text: 'Nové modely za ceny, které stojí za porovnání s kterýmkoli obchodem.', link: 'Zobrazit nové' },
                parts: { word: 'Díly', text: 'SSD, paměti, procesory a další — každá položka s přesným kódem a parametry.', link: 'Zobrazit díly' },
            },
            searchPlaceholder: 'Model, kód nebo part number', searchHint: 'Stiskněte / pro hledání', searchAll: 'Zobrazit všechny výsledky',
            searchNone: 'Nic nenalezeno. Zkuste kratší název modelu nebo kód.', searching: 'Hledám…',
            statLowest: 'Ceny od', statProducts: 'V katalogu', statInStock: 'Skladem', statUpdated: 'Aktualizováno',
            emptyBench: 'Katalog se plní. Nové produkty se zde objeví hned po potvrzení.',
            arrivals: 'Nově přidané', openCatalog: 'Otevřít katalog', categories: 'Kategorie', brands: 'Značky', budgets: 'Podle rozpočtu',
            catalogWord: 'Katalog', justLook: 'Jen se podívejte.', previous: 'Předchozí produkty', next: 'Další produkty',
        },
        attributes: {
            cpu: 'Procesor', gpu: 'Grafická karta', ram: 'Operační paměť', storage: 'Úložiště',
            display: 'Displej', screen_size: 'Úhlopříčka', refresh_rate: 'Obnovovací frekvence',
        },
    },
    uk: {
        searchLabel: 'Пошук', allCategories: 'Усі товари', navigation: 'Головна навігація',
        selectedFilters: 'Вибрані фільтри', removeFilter: 'Прибрати фільтр', closeFilters: 'Закрити фільтри',
        minimumPrice: 'Мінімальна ціна', maximumPrice: 'Максимальна ціна', showResults: 'Показати',
        updating: 'Оновлюю товари…', viewMode: 'Вигляд товарів', viewProduct: 'Деталі товару',
        photoUnavailable: 'Фото відсутнє', previousPage: 'Назад', nextPage: 'Далі',
        skipToProducts: 'Перейти до товарів', invalidPriceRange: 'Мінімальна ціна не може перевищувати максимальну.',
        filterError: 'Не вдалося застосувати фільтри. Перевірте значення та повторіть спробу.',
        updatingFilters: 'Оновлюю кількість товарів у фільтрах…', afterFilterChange: 'Після вибору цієї опції',
        filterCountsHint: 'Числа в дужках показують кількість товарів після вибору опції.',
        priceRange: 'Діапазон ціни', priceOutsideRange: 'Введена межа поза доступними цінами. Її точне значення збережено.',
        noPricedProducts: 'Для цього вибору ціни не вказано.', clearPrice: 'Скинути ціну',
        condition: { new: 'Новий', used: 'Вживаний', refurbished: 'Відновлений', open_box: 'Відкрита упаковка', unknown: 'Стан не вказаний' },
        pageTitle: 'Каталог техніки',
        catalog: 'Каталог техніки',
        search: 'Пошук товарів…',
        filters: 'Фільтри',
        filtersActive: 'активні',
        selectedOptions: 'вибрано',
        reset: 'Скинути',
        resetFilters: 'Скинути фільтри',
        category: 'Категорія',
        price: 'Ціна',
        from: 'від',
        to: 'до',
        apply: 'Застосувати',
        sorting: 'Сортування',
        sortNewest: 'Найновіші',
        sortPriceAsc: 'Найдешевші',
        sortPriceDesc: 'Найдорожчі',
        sortTitle: 'За назвою',
        grid: 'Сітка',
        list: 'Список',
        perPage: 'на сторінці',
        breadcrumbs: 'Ви тут',
        subcategories: 'Підкатегорії',
        moreCount: 'Ще {n}',
        showLess: 'Згорнути',
        emptyTitle: 'Товарів не знайдено',
        emptyText: 'Змініть або скиньте фільтри.',
        priceUnavailable: 'Ціну не вказано',
        tech: 'Техніка',
        pagination: 'Сторінки каталогу',
        language: 'Мова інтерфейсу',
        stock: {
            in_stock: 'У наявності',
            out_of_stock: 'Немає в наявності',
            preorder: 'Передзамовлення',
            unknown: 'Уточнити наявність',
        },
        facets: {
            brands: 'Бренд', types: 'Тип', countries: 'Країна бренду', colors: 'Колір', stock: 'Наявність', condition: 'Стан',
        },
        types: {
            laptop: 'Ноутбук', desktop: 'Готовий ПК', component: 'Комплектуюча', other: 'Інша техніка',
        },
        photoNotice: {
            otherConfiguration: 'На фото інша комплектація в тому самому корпусі; комплектацію цього товару наведено в характеристиках.',
            keyboard: {
                differs: 'Розкладка клавіатури на фото відрізняється від розкладки цього товару.',
                unverified: 'Розкладку клавіатури на фото не вдалося звірити з розкладкою цього товару.',
            },
            markings: 'Наліпки процесора чи відеокарти на фото можуть відрізнятися від комплектації товару.',
        },
        account: {
            partnerLogin: 'Вхід для партнерів', partners: 'Партнерам', signInTitle: 'Вхід',
            signInLead: 'Після входу ви побачите ціни своєї групи.', email: 'Пошта', password: 'Пароль', signIn: 'Увійти',
            inviteOnly: 'Відкритої реєстрації немає: акаунт створюється за посиланням-запрошенням. Попросіть його в нас.',
            wrongCredentials: 'Неправильна пошта або пароль.', throttled: 'Забагато спроб. Спробуйте за хвилину.',
            checkField: 'Перевірте це поле.', joinTitle: 'Реєстрація', joinLead: 'Ваш акаунт бачитиме ціни цієї групи.',
            name: 'Ваше ім’я', company: 'Компанія (необов’язково)', companyLabel: 'Компанія', phone: 'Телефон (необов’язково)',
            passwordConfirm: 'Пароль ще раз', passwordHint: 'Щонайменше 8 символів.',
            passwordMismatch: 'Паролі не збігаються.', emailTaken: 'Ця пошта вже зареєстрована. Увійдіть.',
            join: 'Створити акаунт', haveAccount: 'Вже зареєстровані? Увійдіть', accountTitle: 'Ваш акаунт', group: 'Група',
            groupPrices: 'Ви бачите ціни своєї групи.', regularPrices: 'Ви бачите звичайні ціни.',
            toCatalog: 'До каталогу', signOut: 'Вийти', yourPrice: 'Ваша ціна',
        },
        home: {
            link: 'Головна', title: 'Відновлені та нові ноутбуки й комплектуючі',
            words: ['Знайшли', 'дешевше?', 'Порівняйте'], headline: 'Знайшли дешевше? Порівняйте.',
            lead: 'Відновлені та нові ноутбуки й комплектуючі за цінами, які варто побачити. Просто загляньте в каталог.',
            manifesto: 'Ningredy відновлює ноутбуки й продає нові за цінами, які важко знайти деінде. Кожен перевіряємо до останнього парт-номера. Не вірте на слово — відкрийте каталог і порівняйте.',
            ways: {
                restored: { word: 'Відновлені', text: 'Ноутбуки й техніка після відновлення: перевірені, робочі й помітно дешевші за нові.', link: 'Дивитися відновлені' },
                new: { word: 'Нові', text: 'Нові моделі за цінами, які варто порівняти з будь-яким магазином.', link: 'Дивитися нові' },
                parts: { word: 'Деталі', text: 'SSD, пам’ять, процесори й інше — кожна позиція з точним кодом і характеристиками.', link: 'Дивитися деталі' },
            },
            searchPlaceholder: 'Модель, код або парт-номер', searchHint: 'Натисніть / для пошуку', searchAll: 'Показати всі результати',
            searchNone: 'Нічого не знайдено. Спробуйте коротшу назву моделі або код.', searching: 'Шукаю…',
            statLowest: 'Ціни від', statProducts: 'У каталозі', statInStock: 'У наявності', statUpdated: 'Оновлено',
            emptyBench: 'Каталог наповнюється. Нові товари з’являться тут одразу після підтвердження.',
            arrivals: 'Нещодавно додані', openCatalog: 'Відкрити каталог', categories: 'Категорії', brands: 'Бренди', budgets: 'За бюджетом',
            catalogWord: 'Каталог', justLook: 'Тільки подивіться.', previous: 'Попередні товари', next: 'Наступні товари',
        },
        attributes: {
            cpu: 'Процесор', gpu: 'Відеокарта', ram: 'Оперативна пам’ять', storage: 'Накопичувач',
            display: 'Дисплей', screen_size: 'Діагональ', refresh_rate: 'Частота оновлення',
        },
    },
};

// The shop's languages and the languages product text is translated into come from
// the server (App\Services\Products\CatalogLocales, admin «Языки каталога»); these
// are only the values before the first page arrives.
const shop = ref({ storefront: ['cs', 'uk'], content: ['cs', 'uk'] });
const savedLocale = typeof window !== 'undefined' ? window.localStorage.getItem('catalog_locale') : null;
const locale = ref(messages[savedLocale] ? savedLocale : 'cs');
const storefrontLocales = computed(() => shop.value.storefront);

const configureCatalogLocales = (catalogLocales) => {
    if (! catalogLocales) return;
    const storefront = (catalogLocales.storefront || []).filter((code) => messages[code]);
    shop.value = { storefront: storefront.length ? storefront : ['cs'], content: catalogLocales.content || [] };
    if (! shop.value.storefront.includes(locale.value)) locale.value = shop.value.storefront[0];
    if (typeof document !== 'undefined') document.documentElement.lang = locale.value;
};

const read = (source, path) => path.split('.').reduce((value, key) => value?.[key], source);

const t = (key, fallback = key) => read(messages[locale.value], key) ?? fallback;

const setLocale = (nextLocale) => {
    if (! messages[nextLocale] || ! shop.value.storefront.includes(nextLocale)) return;
    locale.value = nextLocale;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem('catalog_locale', nextLocale);
        document.documentElement.lang = nextLocale;
    }
};

// One rule for every name the catalog shows beside a product - a category, a group of
// characteristics, a characteristic, a filter: the visitor's language, then the shop's main
// language, then English, then the name as stored. Each kind used to have its own order,
// so a missing translation showed a different language in each of them.
const nameChain = computed(() => [...new Set([locale.value, shop.value.storefront[0], 'en'])]);
const pickName = (translations, ...fallbacks) => {
    for (const code of nameChain.value) {
        if (translations?.[code]) return translations[code];
    }
    return fallbacks.find(Boolean) || '';
};
// A characteristic's name: the stored one, then the interface's own for the starter keys.
const attributeName = (key, translations, label) => {
    for (const code of nameChain.value) {
        const found = translations?.[code] || read(messages[code], `attributes.${key}`);
        if (found) return found;
    }
    return label || key || '';
};

const categoryName = (category, fallback = '') => pickName(category?.translations, category?.name, category?.label, fallback);

const productCount = (count) => {
    if (locale.value === 'en') {
        return `${count} ${count === 1 ? 'product' : 'products'}`;
    }

    if (locale.value === 'cs') {
        return `${count} ${count === 1 ? 'produkt' : count >= 2 && count <= 4 ? 'produkty' : 'produktů'}`;
    }

    const mod10 = count % 10;
    const mod100 = count % 100;
    const noun = mod10 === 1 && mod100 !== 11
        ? 'товар'
        : mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? 'товари' : 'товарів';

    return `${count} ${noun}`;
};

const localeTag = computed(() => ({ cs: 'cs-CZ', uk: 'uk-UA', en: 'en-US' })[locale.value] || 'en-US');

// "5 more" under a folded list: the number set in the visitor's language.
const moreCount = (count) => t('moreCount').replace('{n}', new Intl.NumberFormat(localeTag.value).format(count));

// A product's own text is written in English and translated into the languages the
// admin chose (operator, 2026-11-01: Czech and Ukrainian). The shown language is the
// interface's own when the bot translates into it; a field without that translation
// falls back to the shop's main language, and only then to the English source.
const mainContentLocale = computed(() => shop.value.storefront.find((code) => shop.value.content.includes(code)) || 'en');
const contentLocale = computed(() => (shop.value.content.includes(locale.value) ? locale.value : mainContentLocale.value));
const contentChain = computed(() => [...new Set([contentLocale.value, mainContentLocale.value])].filter((code) => code !== 'en'));

// A flat translation map ({cs: '…', uk: '…'}): labels and values.
const translatedValue = (translations) => {
    for (const code of contentChain.value) {
        if (translations?.[code]) return translations[code];
    }
    return null;
};

// A card's translation map ({cs: {title, description}, …}), or several in priority order
// (a variant's, then its product's): one field, language first - the visitor's language
// from any of them before the main shop language from any of them.
const translatedText = (translations, field) => {
    const maps = Array.isArray(translations) ? translations : [translations];
    for (const code of contentChain.value) {
        for (const map of maps) {
            if (map?.[code]?.[field]) return map[code][field];
        }
    }
    return null;
};

const attributeLabel = (attribute) => attributeName(attribute?.key, attribute?.label_translations, attribute?.label);

const attributeValue = (attribute) => translatedValue(attribute?.translations) || attribute?.value;

// A filter is named like its attribute.
const facetLabel = (facet) => attributeName(facet?.key, facet?.label_translations, facet?.label);

const facetOptionLabel = (option) => translatedValue(option?.translations) || option?.label;
const attributeGroupName = (group) => pickName(group?.translations, group?.name);

// "15,6 inch" is spelled out in the value already; a unit is added only to a
// bare number, so a value is never followed by its own unit twice. A bare number
// with a translation was translated with its unit (a unit typed in Cyrillic, «16 ГБ»):
// the translation carries the unit in the visitor's language.
const attributeUnit = (attribute) => (attribute?.unit && /^[\d\s.,]+$/.test(String(attribute?.value ?? ''))
    && ! translatedValue(attribute?.translations)
    ? attribute.unit
    : null);

// A configuration's own words, its name and its colour, in the visitor's language.
const variantText = (variant, field) => translatedText(variant?.translations, field) || variant?.[field] || null;

// A brand's country is a name typed in the admin; it is named like a category.
const countryName = (brand) => pickName(brand?.country_translations, brand?.country);

// What the shop says under a photo. The server sends facts, not sentences: the reviewers'
// notes are written for the operator in Russian and once stood under the gallery as they were.
const photoNoticeLines = (notice) => {
    if (! notice) return [];

    return [
        notice.other_configuration ? t('photoNotice.otherConfiguration') : '',
        notice.keyboard ? t(`photoNotice.keyboard.${notice.keyboard}`, '') : '',
        notice.markings_may_differ ? t('photoNotice.markings') : '',
    ].filter(Boolean);
};

if (typeof document !== 'undefined') document.documentElement.lang = locale.value;

export { configureCatalogLocales };

export const useI18n = () => ({
    locale, localeTag, setLocale, t, productCount, moreCount, categoryName, storefrontLocales,
    contentLocale, translatedText, attributeLabel, attributeValue, attributeUnit, attributeGroupName, facetLabel, facetOptionLabel,
    photoNoticeLines, variantText, countryName,
});
