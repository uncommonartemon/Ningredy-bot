# Pull request description

Written in Russian, for the person who runs the shop. They read what changed for them and what to press; they do not read code. Short sentences, the words on the buttons, no internal names unless a developer needs them to find the place.

```markdown
## Что

Модуль NN: одно предложение о том, что теперь можно. Включает #A–#B.

## Зачем

Решение от ГГГГ-ММ-ДД: само решение нейтральными словами.
Что было не так раньше — одно-два предложения.

## Как теперь

- Что делает программа, по одному пункту на изменение.
- Где это видно: какая страница, какая кнопка.

## Проверка

- Какие тесты добавлены и что они проверяют.
- Прогнан ли весь набор.
- Что смотрели глазами и где.
- Чего не проверяли и почему.
```

Rules:

- The first section answers "what is this" in one line.
- "Зачем" states the decision and its date. No one's words, quoted or retold.
- "Проверка" is honest: a check that was not run is named as not run.
- When the change replaces a rule recorded in the strategy, give it a section of its own that says which rule and how to keep the old behaviour (merge the previous PR instead).
- End with the attribution line the session provides.
