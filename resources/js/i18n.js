import { computed, ref } from 'vue';

const messages = {
    en: {
        pageTitle: 'Electronics catalog', catalog: 'Electronics catalog', search: 'Search products…',
        filters: 'Filters', filtersActive: 'active', reset: 'Reset', resetFilters: 'Reset filters',
        category: 'Category', price: 'Price', from: 'from', to: 'to', apply: 'Apply', sorting: 'Sorting',
        sortNewest: 'Newest first', sortPriceAsc: 'Price: low to high', sortPriceDesc: 'Price: high to low', sortTitle: 'By title',
        grid: 'Grid', list: 'List', emptyTitle: 'No products found', emptyText: 'Change or reset the filters.',
        perPage: 'per page',
        priceUnavailable: 'Price not available', tech: 'Electronics', pagination: 'Catalog pagination', language: 'Interface language',
        stock: { in_stock: 'In stock', out_of_stock: 'Out of stock', preorder: 'Pre-order', unknown: 'Check availability' },
        facets: { brands: 'Brand', types: 'Type', countries: 'Brand country', colors: 'Color', stock: 'Availability' },
        types: { laptop: 'Laptop', desktop: 'Desktop PC', component: 'Component', other: 'Other electronics' },
        attributes: {
            cpu: 'Processor', gpu: 'Graphics card', ram: 'Memory', storage: 'Storage',
            display: 'Display', screen_size: 'Screen size', refresh_rate: 'Refresh rate',
        },
    },
    cs: {
        pageTitle: 'Katalog techniky',
        catalog: 'Katalog techniky',
        search: 'Hledat produkty…',
        filters: 'Filtry',
        filtersActive: 'aktivní',
        reset: 'Vymazat',
        resetFilters: 'Vymazat filtry',
        category: 'Kategorie',
        price: 'Cena',
        from: 'od',
        to: 'do',
        apply: 'Použít',
        sorting: 'Řazení',
        sortNewest: 'Nejnovější',
        sortPriceAsc: 'Cena: nejnižší',
        sortPriceDesc: 'Cena: nejvyšší',
        sortTitle: 'Podle názvu',
        grid: 'Mřížka',
        list: 'Seznam',
        perPage: 'na stránku',
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
            brands: 'Značka', types: 'Typ', countries: 'Země značky', colors: 'Barva', stock: 'Dostupnost',
        },
        types: {
            laptop: 'Notebook', desktop: 'Stolní počítač', component: 'Komponenta', other: 'Ostatní technika',
        },
        attributes: {
            cpu: 'Procesor', gpu: 'Grafická karta', ram: 'Operační paměť', storage: 'Úložiště',
            display: 'Displej', screen_size: 'Úhlopříčka', refresh_rate: 'Obnovovací frekvence',
        },
    },
    uk: {
        pageTitle: 'Каталог техніки',
        catalog: 'Каталог техніки',
        search: 'Пошук товарів…',
        filters: 'Фільтри',
        filtersActive: 'активні',
        reset: 'Скинути',
        resetFilters: 'Скинути фільтри',
        category: 'Категорія',
        price: 'Ціна',
        from: 'від',
        to: 'до',
        apply: 'Застосувати',
        sorting: 'Сортування',
        sortNewest: 'Спочатку нові',
        sortPriceAsc: 'Ціна: дешевші',
        sortPriceDesc: 'Ціна: дорожчі',
        sortTitle: 'За назвою',
        grid: 'Сітка',
        list: 'Список',
        perPage: 'на сторінці',
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
            brands: 'Бренд', types: 'Тип', countries: 'Країна бренду', colors: 'Колір', stock: 'Наявність',
        },
        types: {
            laptop: 'Ноутбук', desktop: 'Готовий ПК', component: 'Комплектуюча', other: 'Інша техніка',
        },
        attributes: {
            cpu: 'Процесор', gpu: 'Відеокарта', ram: 'Оперативна пам’ять', storage: 'Накопичувач',
            display: 'Дисплей', screen_size: 'Діагональ', refresh_rate: 'Частота оновлення',
        },
    },
};

const supportedLocales = ['cs', 'uk', 'en'];
const savedLocale = typeof window !== 'undefined' ? window.localStorage.getItem('catalog_locale') : null;
const locale = ref(supportedLocales.includes(savedLocale) ? savedLocale : 'cs');

const read = (source, path) => path.split('.').reduce((value, key) => value?.[key], source);

const t = (key, fallback = key) => read(messages[locale.value], key) ?? fallback;

const setLocale = (nextLocale) => {
    if (! messages[nextLocale]) return;
    locale.value = nextLocale;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem('catalog_locale', nextLocale);
        document.documentElement.lang = nextLocale;
    }
};

const categoryName = (category, fallback = '') => category?.translations?.[locale.value]
    || category?.name
    || category?.label
    || fallback;

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

if (typeof document !== 'undefined') document.documentElement.lang = locale.value;

export const useI18n = () => ({ locale, localeTag, setLocale, t, productCount, categoryName });
