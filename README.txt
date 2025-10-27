Woo MyGLS (DocSpec REST) – v1.0.5

MyGLS HU REST/JSON integráció a hivatalos dokumentáció alapján (ver. 25.07.08). HPOS-kompatibilis.

Funkciók
- REST hívások: PrintLabels, GetParcelStatuses, DeleteLabels, ModifyCOD, GetParcelList, GetClientReturnAddress
- SHA512 jelszó: módok – base64 (alapértelmezett), JSON byte array, hex
- Admin tesztek: Ping (GetClientReturnAddress), Demó PrintLabels (PDF render)
- Pénztár mező: PSD StringValue (ParcelShop/Locker ID) manuális megadás
- Bulk: MyGLS címke (PrintLabels), menti a csomagszámot + tracking URL-t
- HPOS (custom order tables) támogatás

Beállítások
- API Base URL: ha üres, a Környezet alapján töltődik (test/prod)
- ClientNumber: szerződésed GLS ügyfélszáma
- TypeOfPrinter: pl. ThermoZPL_300DPI / ShipItThermoPdf / A4_2x2
- Feladó JSON: PickupAddress objektum (Name, Street, HouseNumber, City, ZipCode, CountryIsoCode, Contact* mezők).

Megjegyzések
- A PrintLabels válaszban a Labels mező base64 PDF. Célszerű azonnal lementeni a rendeléshez.
- A GetPrintedLabels hivatalosan ParcelIdList-et vár; a PrintLabelsInfo-ban nem minden esetben jön ParcelId. Ha kell, bővítsd a mentést a teljes válaszobjektummal.
- A PSD szolgáltatás (ParcelShop Delivery) kötelező kontakt mezőket kér a DeliveryAddress-ben (ContactName/Phone/Email) – a plugin ezeket tölti a rendelés adataiból.
