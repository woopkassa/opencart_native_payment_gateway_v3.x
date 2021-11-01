# Opencart native payment gateway v3.x

Установка модуля в Opencart 3.x:

Перейти в раздел администратора

Скопировать папки Admin и Catalog в архиве модуля и вставить в корневую директорию Opencart.

Перейти на страницу Extensions, выбрать extension type: Payments. В списке найти Wooppay или Wooppay Mobile, в зависимости от того, какой модуль устанавливается.

В настройках  ввести ваши данные. Пример:

API URL: https://api.yii2-stage.test.wooppay.com/v1

API Username: test_merch

API Password: A12345678a

Order prefix: card

Service name: test_merch_invoice

Ссылку API URL можно взять в кабинете мерчанта, в разделе Online прием платежей -> API

Поле "Место оплаты" дает выбор, где пользователь будет оплачивать инвойс, с редиректом на wooppay или оставаясь на сайте магазина.

Поле "Поле привязывать карты покупателей" нужно для сохранения карт при оплате и их последующего использования.

Перейти в System->Users->User Groups->Edit Administrator, найти нужный модуль, поставить галочку и сохранить.

Перейти в магазин и произвести оплату.



