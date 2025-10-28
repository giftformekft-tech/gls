# MyGLS WooCommerce Integration

Teljes MyGLS API integráció WooCommerce-hez interaktív térképes csomagpont választóval, automatikus és bulk címkegenerálással, valamint valós idejű státusz követéssel.

## ✨ Főbb Funkciók

### 🎯 Csomagpont Integráció
- ✅ Interaktív térkép (Leaflet) csomagpont kiválasztáshoz
- ✅ Keresés irányítószám vagy városnév alapján
- ✅ Geolokáció támogatás ("Használd a helyzetem")
- ✅ Szép, modern UI responsiv dizájnnal
- ✅ Valós idejű távolság számítás

### 📦 Automatikus Címkegenerálás
- ✅ Automatikus címke létrehozás rendelés státusz változáskor
- ✅ Bulk címkegenerálás több rendeléshez egyszerre
- ✅ Bulk címke letöltés ZIP formátumban
- ✅ Bulk címke törlés
- ✅ PDF címke letöltés egyenként

### 📊 Csomag Státusz Követés
- ✅ Automata státusz szinkronizálás GLS API-val
- ✅ Státusz előzmények megjelenítése timeline formában
- ✅ Rendelés jegyzet automatikus frissítése
- ✅ Automata rendelés státusz változtatás (kézbesítéskor)
- ✅ Manuális státusz frissítés gomb

### 🛠️ Admin Funkciók
- ✅ Szép, modern beállítási felület
- ✅ API kapcsolat tesztelése
- ✅ Több ország támogatás (HU, HR, CZ, RO, SI, SK, RS)
- ✅ Test mode támogatás
- ✅ Különböző nyomtató típusok (A4, Thermal, ZPL)
- ✅ Metabox minden rendelésnél
- ✅ Követési link automatikus generálás

## 📋 Követelmények

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- MySQL 5.7+
- MyGLS fiók és API hozzáférés

## 🚀 Telepítés

### 1. Fájlok Feltöltése

Töltsd fel a plugin fájlokat a következő struktúrában:

```
wp-content/plugins/mygls-woocommerce/
├── mygls-woocommerce.php          (Main plugin file)
├── includes/
│   ├── API/
│   │   └── Client.php
│   ├── Admin/
│   │   ├── Settings.php
│   │   ├── OrderMetaBox.php
│   │   └── BulkActions.php
│   ├── Shipping/
│   │   └── Method.php
│   ├── Parcelshop/
│   │   └── Selector.php
│   └── Tracking/
│       └── StatusSync.php
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js
│       └── parcelshop-map.js
└── languages/
```

### 2. Plugin Aktiválás

1. Menj a WordPress admin felületre
2. Plugins → Installed Plugins
3. Keresd meg a "MyGLS WooCommerce Integration" plugint
4. Kattints az "Activate" gombra

### 3. Beállítások Konfigurálása

Menj a **MyGLS → Settings** menüpontba és töltsd ki a következő mezőket:

#### API Kapcsolat
- **Country**: Válaszd ki az országot (pl. Hungary)
- **Username**: MyGLS fiók email címed
- **Password**: MyGLS fiók jelszavad
- **Client Number**: GLS kliens számod
- **Test Mode**: Kapcsold be a teszteléshez

#### Feladó Cím (Pickup Address)
- Töltsd ki a teljes feladói címet
- Ez lesz a címkéken a feladó

#### Címke Beállítások
- **Printer Type**: Válaszd ki a nyomtató típusod
- **Auto-generate Labels**: Automatikus címke generálás bekapcsolása
- **Auto Status Sync**: Automatikus státusz szinkronizálás
- **Sync Interval**: Frissítési gyakoriság (percben)

### 4. Szállítási Zóna Beállítása

1. Menj a **WooCommerce → Settings → Shipping → Zones**
2. Válaszd ki a zónát vagy hozz létre újat
3. Kattints az "Add shipping method" gombra
4. Válaszd a "GLS Shipping" opciót
5. Konfiguráld a szállítási módot:
   - **Shipping Type**: Home Delivery vagy ParcelShop Delivery
   - **Cost**: Szállítási díj
   - **Free Shipping Threshold**: Ingyenes szállítás értékhatár

## 📖 Használat

### Rendelés Leadása (Frontend)

1. A vásárló a checkout oldalon kiválasztja a GLS szállítási módot
2. Ha ParcelShop szállítás van beállítva:
   - Megjelenik a "Select Parcelshop" gomb
   - Térképes választó nyílik meg
   - Keresés irányítószám vagy város alapján
   - Kiválasztás után automatikus mentés

### Címke Generálás (Admin)

#### Egyedi Címke
1. Nyisd meg a rendelést
2. Jobb oldalt megjelenik a "GLS Shipping Label" metabox
3. Kattints a "Generate Shipping Label" gombra
4. A címke automatikusan generálódik
5. Letöltheted PDF formátumban

#### Bulk Címkegenerálás
1. Menj a **WooCommerce → Orders** oldalra
2. Jelöld ki a rendeléseket
3. Bulk Actions → "Generate GLS Labels"
4. Kattints az "Apply" gombra
5. Több címke egyszerre ZIP-ben: "Download GLS Labels"

### Státusz Követés

#### Automatikus
- A rendszer rendszeres időközönként frissíti a csomagok státuszát
- Rendelés jegyzetben látható az előzmény
- Automatikus rendelés státusz váltás kézbesítéskor

#### Manuális
1. Nyisd meg a rendelést
2. A metaboxban kattints a "Refresh Status" gombra
3. A státusz előzmények megjelennek timeline formában

## 🎨 Testreszabás

### Csomagpont Választó Testreszabása

```php
// Csomagpont választó megjelenítésének szabályozása
add_filter('mygls_show_parcelshop_selector', function($show, $method) {
    // Csak bizonyos szállítási módokhoz mutasd
    return $method->get_option('shipping_type') === 'parcelshop';
}, 10, 2);
```

### Parcel Adatok Módosítása

```php
// Parcel adatok módosítása generálás előtt
add_filter('mygls_parcel_data', function($parcel, $order_id) {
    // Például extra szolgáltatás hozzáadása
    $parcel['ServiceList'][] = [
        'Code' => 'INS',
        'INSParameter' => [
            'Value' => 50000
        ]
    ];
    return $parcel;
}, 10, 2);
```

### Címke Generálás Után Hook

```php
// Saját logika címke generálás után
add_action('mygls_label_generated', function($order_id, $parcel_number) {
    // Például email küldés
    // Vagy saját rendszer frissítése
}, 10, 2);
```

## 🔧 API Végpontok

### Parcelshop Keresés

```javascript
jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'mygls_get_parcelshops',
        nonce: nonce,
        zip: '2351',
        city: 'Alsónémedi'
    }
});
```

### Címke Generálás

```javascript
jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'mygls_generate_label',
        nonce: nonce,
        order_id: 123
    }
});
```

### Státusz Frissítés

```javascript
jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'mygls_refresh_status',
        nonce: nonce,
        parcel_number: 1234567890
    }
});
```

## 📊 Adatbázis Struktúra

A plugin létrehoz egy táblát a címkék tárolásához:

```sql
CREATE TABLE wp_mygls_labels (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    order_id bigint(20) NOT NULL,
    parcel_id bigint(20) NOT NULL,
    parcel_number bigint(20) NOT NULL,
    tracking_url varchar(255),
    label_pdf longblob,
    status varchar(50) DEFAULT 'pending',
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY (id),
    KEY order_id (order_id),
    KEY parcel_number (parcel_number)
);
```

## 🌍 Támogatott Országok

- 🇭🇺 Hungary (HU) - `api.mygls.hu`
- 🇭🇷 Croatia (HR) - `api.mygls.hr`
- 🇨🇿 Czechia (CZ) - `api.mygls.cz`
- 🇷🇴 Romania (RO) - `api.mygls.ro`
- 🇸🇮 Slovenia (SI) - `api.mygls.si`
- 🇸🇰 Slovakia (SK) - `api.mygls.sk`
- 🇷🇸 Serbia (RS) - `api.mygls.rs`

## 🔐 Támogatott Szolgáltatások

- **PSD** - ParcelShop Delivery
- **COD** - Cash on Delivery (utánvétel)
- **FDS** - Flexible Delivery Service (email értesítés)
- **SM2** - SMS Pre-advice (SMS értesítés)
- **INS** - Insurance (biztosítás)
- **DDS** - Day Definite Service
- **SDS** - Scheduled Delivery Service
- **24H** - 24 órás kézbesítés
- **SAT** - Szombati kézbesítés
- **XS** - Exchange Service
- **PRS** - Pick & Return Service
- **TGS** - Think Green Service

## 🖨️ Nyomtató Típusok

- **A4_2x2** - A4 papír, 4 címke oldalanként (2x2)
- **A4_4x1** - A4 papír, 4 címke oldalanként (4x1)
- **Connect** - GLS Connect nyomtató
- **Thermo** - Thermal nyomtató
- **ThermoZPL** - Thermal ZPL formátum
- **ThermoZPL_300DPI** - Thermal ZPL 300 DPI

## 🐛 Hibakeresés

### Debug Mode Bekapcsolása

Add hozzá a `wp-config.php` fájlhoz:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

A logok itt találhatók: `wp-content/debug.log`

### GLS API Logok

A plugin automatikusan naplózza az API hívásokat:

```php
// Log szintek: debug, info, warning, error
mygls_log('Üzenet', 'debug');
```

Nézd meg a logokat: **WooCommerce → Status → Logs** → válaszd a `mygls` forrást.

### Gyakori Hibák

#### "Unauthorized" hiba
- Ellenőrizd a username és password helyességét
- Bizonyosodj meg róla, hogy a SHA512 hash helyesen generálódik

#### "Label generation failed"
- Ellenőrizd a feladói cím minden mezőjét
- Győződj meg róla, hogy a Client Number helyes
- Test mode-ban próbáld újra

#### Csomagpont térkép nem töltődik be
- Ellenőrizd, hogy a Leaflet library betöltődött-e
- Nézd meg a browser console-t hibákért
- Tisztítsd a cache-t

## 🔄 Frissítés

1. Mentsd el az aktuális fájlokat (backup)
2. Deaktiváld a plugint
3. Töröld a régi fájlokat
4. Töltsd fel az új fájlokat
5. Aktiváld újra a plugint
6. Ellenőrizd a beállításokat

## 📝 Változási Napló

### 1.0.0 (2024-10-28)
- ✨ Kezdeti kiadás
- ✅ MyGLS API teljes integráció
- ✅ Interaktív térképes csomagpont választó
- ✅ Automatikus és bulk címkegenerálás
- ✅ Valós idejű státusz szinkronizálás
- ✅ Modern, responsiv admin UI
- ✅ 7 ország támogatása
- ✅ WooCommerce 9.0 kompatibilitás

## 📞 Támogatás

### Dokumentáció
- **GLS API Dokumentáció**: https://api.mygls.hu/
- **WordPress Codex**: https://codex.wordpress.org/
- **WooCommerce Docs**: https://woocommerce.com/documentation/

### Kapcsolat
- **Email**: support@yourcompany.com
- **GitHub**: https://github.com/yourusername/mygls-woocommerce
- **GLS Support**: https://gls-group.eu/

## ⚖️ Licensz

GPL v2 or later

## 🙏 Köszönetnyilvánítás

- GLS Group - MyGLS API
- Leaflet - Térképes megjelenítés
- WooCommerce Community
- WordPress Community

## 🔒 Biztonsági Megjegyzések

- Soha ne oszd meg az API hitelesítési adataidat
- Test mode-ot használj fejlesztéshez
- Rendszeresen frissítsd a plugint
- Készíts rendszeres biztonsági mentést
- HTTPS használata kötelező production környezetben

## 🚦 Teljesítmény

- Optimalizált API hívások (batch processing)
- Cache-elt csomagpont adatok
- Lazy loading a térképnél
- Minimalizált CSS/JS fájlok
- Database index használata

## ✅ Tesztelés

### Unit Tesztek
```bash
# PHPUnit futtatása
./vendor/bin/phpunit
```

### API Teszt
1. Menj a Settings oldalra
2. Kattints a "Test Connection" gombra
3. Sikeres kapcsolat esetén zöld jelzés

### Funkcionális Teszt Checklist
- [ ] Beállítások mentése
- [ ] API kapcsolat
- [ ] Szállítási mód megjelenítése
- [ ] Csomagpont választó megnyitása
- [ ] Csomagpont keresés
- [ ] Címke generálás
- [ ] Címke letöltés
- [ ] Bulk műveletek
- [ ] Státusz szinkronizálás

## 🎯 Roadmap

### v1.1.0 (Tervezett)
- [ ] Több csomagpont provider támogatás
- [ ] Saját térkép style-ok
- [ ] CSV export funkcionalitás
- [ ] Webhook támogatás
- [ ] REST API endpoint-ok

### v1.2.0 (Tervezett)
- [ ] Multi-vendor támogatás
- [ ] B2B funkciók
- [ ] Automatikus súly számítás
- [ ] Címke sablonok

---

**Készítette**: Your Name  
**Verzió**: 1.0.0  
**Utolsó frissítés**: 2024-10-28  
**WordPress verzió**: 6.8.3+  
**PHP verzió**: 8.2.28+