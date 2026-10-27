import { ref, watch } from 'vue'

export type Locale = 'en' | 'ru' | 'lv'

const storageKey = 'dataflow:locale'
const stored = localStorage.getItem(storageKey)
export const locale = ref<Locale>(stored === 'ru' || stored === 'lv' ? stored : 'en')

export const localeOptions: { value: Locale; label: string }[] = [
	{ value: 'en', label: 'English' },
	{ value: 'ru', label: 'Русский' },
	{ value: 'lv', label: 'Latviešu' },
]

const russian: Record<string, string> = {
	Overview: 'Обзор',
	Projects: 'Проекты',
	'Sign out': 'Выйти',
	Register: 'Зарегистрироваться',
	'Sign in': 'Войти',
	'Sign in to DataFlow': 'Вход в DataFlow',
	'Sign in to access your organizations and projects.':
		'Войдите, чтобы работать со своими организациями и проектами.',
	'No account?': 'Нет аккаунта?',
	Language: 'Язык',
	Password: 'Пароль',
	'Signing in…': 'Вход…',
	'Create an account': 'Создать аккаунт',
	'A personal organization will be created automatically.':
		'Личная организация будет создана автоматически.',
	Name: 'Имя',
	Description: 'Описание',
	'(optional)': '(необязательно)',
	'Confirm password': 'Подтвердите пароль',
	'Creating…': 'Создание…',
	'Already have an account?': 'Уже есть аккаунт?',
	'Choose an organization': 'Выберите организацию',
	'An organization defines access boundaries for projects and data.':
		'Организация определяет границы доступа к проектам и данным.',
	'Loading…': 'Загрузка…',
	'No organizations available': 'Нет доступных организаций',
	'Creation and invitations will be added in the next organization-management stage.':
		'Создание и приглашения появятся на следующем этапе.',
	'Recent runs across all organization pipelines.': 'Последние запуски всех потоков организации.',
	Refresh: 'Обновить',
	Status: 'Статус',
	'All statuses': 'Все статусы',
	Queued: 'В очереди',
	Running: 'Выполняется',
	Succeeded: 'Завершён',
	Failed: 'Ошибка',
	'Loading runs…': 'Загрузка запусков…',
	'No runs match the selected status.': 'Запусков с выбранным статусом пока нет.',
	'Error code:': 'Код ошибки:',
	Previous: 'Назад',
	Next: 'Далее',
	'Go to projects': 'Перейти к проектам',
	'Back to projects': 'К проектам',
	'Loading project…': 'Загрузка проекта…',
	'Project pipelines and their settings.': 'Потоки проекта и их настройки.',
	Pipelines: 'Потоки',
	'Pipeline name': 'Название потока',
	'Create pipeline': 'Создать поток',
	'Loading pipelines…': 'Загрузка потоков…',
	'No pipelines yet.': 'Пока нет потоков.',
	'Project settings': 'Настройки проекта',
	'Save changes': 'Сохранить изменения',
	'Read-only': 'Только просмотр',
	'Your role does not allow editing this project.': 'Ваша роль не позволяет менять этот проект.',
	Save: 'Сохранить',
	Validate: 'Проверить',
	Saved: 'Сохранено',
	'Unsaved changes': 'Есть несохранённые изменения',
	'Back to project': 'К проекту',
	Runs: 'Запуски',
	Run: 'Запустить',
	'No runs yet.': 'Запусков пока нет.',
	'Recent runs': 'Последние запуски',
	'CSV source': 'CSV источник',
	Filter: 'Фильтр',
	'Map / Rename': 'Сопоставление',
	'CSV destination': 'CSV результат',
	'On canvas': 'На схеме',
	'No nodes on the canvas yet.': 'Блоков пока нет.',
	Nodes: 'Блоки',
	'Add a node to the canvas': 'Добавьте блок на схему',
	'Add the first node from the catalog': 'Добавьте первый блок из каталога',
	'CSV file': 'CSV файл',
	'Choose a file': 'Выберите файл',
	'Upload CSV (up to 10 MiB)': 'Загрузить CSV (до 10 МиБ)',
	Delimiter: 'Разделитель',
	Comma: 'Запятая',
	Semicolon: 'Точка с запятой',
	Colon: 'Двоеточие',
	Pipe: 'Вертикальная черта',
	'First row is a header': 'Первая строка — заголовок',
	'Field name': 'Имя поля',
	Type: 'Тип',
	'Allows empty values': 'Допускает пустое значение',
	'Add field': 'Добавить поле',
	'Remove field': 'Удалить поле',
	Field: 'Поле',
	Condition: 'Условие',
	Value: 'Значение',
	Equals: 'Равно',
	'Does not equal': 'Не равно',
	'Is empty': 'Пусто',
	'Is not empty': 'Не пусто',
	'Greater than': 'Больше',
	'Less than': 'Меньше',
	'Source field': 'Исходное поле',
	'New name': 'Новое имя',
	'Remove node': 'Удалить блок',
	Connections: 'Связи',
	Output: 'Выход',
	Input: 'Вход',
	Connect: 'Соединить',
	'Remove connection': 'Удалить связь',
}

const latvian: Record<string, string> = {
	Overview: 'Pārskats',
	Projects: 'Projekti',
	'Sign out': 'Izrakstīties',
	Register: 'Reģistrēties',
	'Sign in': 'Pieteikties',
	'Sign in to DataFlow': 'Pieteikšanās DataFlow',
	'Sign in to access your organizations and projects.':
		'Piesakieties, lai piekļūtu savām organizācijām un projektiem.',
	'No account?': 'Nav konta?',
	Language: 'Valoda',
	Password: 'Parole',
	'Signing in…': 'Piesakās…',
	'Create an account': 'Izveidot kontu',
	'A personal organization will be created automatically.':
		'Personiskā organizācija tiks izveidota automātiski.',
	Name: 'Nosaukums',
	Description: 'Apraksts',
	'(optional)': '(nav obligāts)',
	'Confirm password': 'Apstipriniet paroli',
	'Creating…': 'Veido…',
	'Already have an account?': 'Jau ir konts?',
	'Choose an organization': 'Izvēlieties organizāciju',
	'An organization defines access boundaries for projects and data.':
		'Organizācija nosaka piekļuves robežas projektiem un datiem.',
	'Loading…': 'Ielādē…',
	'No organizations available': 'Nav pieejamu organizāciju',
	'Recent runs across all organization pipelines.': 'Pēdējās visu organizācijas plūsmu izpildes.',
	Refresh: 'Atjaunināt',
	Status: 'Statuss',
	'All statuses': 'Visi statusi',
	Queued: 'Rindā',
	Running: 'Tiek izpildīts',
	Succeeded: 'Pabeigts',
	Failed: 'Kļūda',
	'Loading runs…': 'Ielādē izpildes…',
	'No runs match the selected status.': 'Nav izpilžu ar izvēlēto statusu.',
	'Error code:': 'Kļūdas kods:',
	Previous: 'Iepriekšējā',
	Next: 'Nākamā',
	'Go to projects': 'Doties uz projektiem',
	'Back to projects': 'Atpakaļ uz projektiem',
	'Loading project…': 'Ielādē projektu…',
	'Project pipelines and their settings.': 'Projekta plūsmas un to iestatījumi.',
	Pipelines: 'Plūsmas',
	'Pipeline name': 'Plūsmas nosaukums',
	'Create pipeline': 'Izveidot plūsmu',
	'Loading pipelines…': 'Ielādē plūsmas…',
	'No pipelines yet.': 'Vēl nav plūsmu.',
	'Project settings': 'Projekta iestatījumi',
	'Save changes': 'Saglabāt izmaiņas',
	'Read-only': 'Tikai skatīšana',
	Save: 'Saglabāt',
	Validate: 'Pārbaudīt',
	Saved: 'Saglabāts',
	'Unsaved changes': 'Nesaglabātas izmaiņas',
	'Back to project': 'Atpakaļ uz projektu',
	Runs: 'Izpildes',
	Run: 'Palaist',
	'No runs yet.': 'Vēl nav izpilžu.',
	'Recent runs': 'Pēdējās izpildes',
	'CSV source': 'CSV avots',
	Filter: 'Filtrs',
	'Map / Rename': 'Lauku pārsaukšana',
	'CSV destination': 'CSV rezultāts',
	'On canvas': 'Shēmā',
	'No nodes on the canvas yet.': 'Shēmā vēl nav mezglu.',
	Nodes: 'Mezgli',
	'Add a node to the canvas': 'Pievienojiet mezglu shēmai',
	'Add the first node from the catalog': 'Pievienojiet pirmo mezglu no kataloga',
	'CSV file': 'CSV fails',
	'Choose a file': 'Izvēlieties failu',
	'Upload CSV (up to 10 MiB)': 'Augšupielādēt CSV (līdz 10 MiB)',
	Delimiter: 'Atdalītājs',
	Comma: 'Komats',
	Semicolon: 'Semikols',
	Colon: 'Kols',
	Pipe: 'Vertikālā svītra',
	'First row is a header': 'Pirmā rinda ir virsraksts',
	'Field name': 'Lauka nosaukums',
	Type: 'Tips',
	'Allows empty values': 'Atļauj tukšas vērtības',
	'Add field': 'Pievienot lauku',
	'Remove field': 'Noņemt lauku',
	Field: 'Lauks',
	Condition: 'Nosacījums',
	Value: 'Vērtība',
	Equals: 'Vienāds ar',
	'Does not equal': 'Nav vienāds ar',
	'Is empty': 'Ir tukšs',
	'Is not empty': 'Nav tukšs',
	'Greater than': 'Lielāks par',
	'Less than': 'Mazāks par',
	'Source field': 'Avota lauks',
	'New name': 'Jaunais nosaukums',
	'Remove node': 'Noņemt mezglu',
	Connections: 'Savienojumi',
	Output: 'Izvade',
	Input: 'Ievade',
	Connect: 'Savienot',
	'Remove connection': 'Noņemt savienojumu',
}

const catalogs: Record<Locale, Record<string, string>> = { en: {}, ru: russian, lv: latvian }
const originals = new WeakMap<Text, string>()

function translatedText(value: string): string {
	return catalogs[locale.value][value] ?? value
}

function translateTextNode(node: Text): void {
	const source = originals.get(node) ?? node.nodeValue ?? ''
	originals.set(node, source)
	const match = /^(\s*)(.*?)(\s*)$/s.exec(source)
	if (!match) return
	const next = `${match[1] ?? ''}${translatedText(match[2] ?? '')}${match[3] ?? ''}`
	if (node.nodeValue !== next) node.nodeValue = next
}

function translateTree(root: Node): void {
	if (root.nodeType === Node.TEXT_NODE) translateTextNode(root as Text)
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
	let node: Node | null
	while ((node = walker.nextNode())) translateTextNode(node as Text)
}

export function startLocalization(): () => void {
	const observer = new MutationObserver((records) => {
		for (const record of records) {
			if (record.type === 'characterData') translateTextNode(record.target as Text)
			else record.addedNodes.forEach(translateTree)
		}
	})
	translateTree(document.body)
	observer.observe(document.body, { childList: true, subtree: true, characterData: true })
	const stop = watch(locale, () => {
		localStorage.setItem(storageKey, locale.value)
		translateTree(document.body)
	})
	return () => {
		stop()
		observer.disconnect()
	}
}
