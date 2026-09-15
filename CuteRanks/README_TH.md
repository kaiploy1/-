# CuteRanks v2.0.1 (PocketMine-MP 5)

แพ็กนี้เป็น CuteRanks ที่รวมไฟล์แก้ไขจากชุดที่ส่งมา โดยยึดโค้ดใน ZIP เดิมเป็นตัวหลัก
และนำส่วนแก้ไขที่ส่งแยกมา merge เข้ากับตัวหลัก

## สิ่งที่รวม/แก้

1. Custom Pet Entity ถูกลงทะเบียนด้วย EntityFactory ก่อนสร้างสัตว์
   - Wolf
   - Rabbit
   - Fox
   - Cat
   - Bee

2. PetEntity ปรับปรุงการติดตามผู้เล่น
   - ไม่มีแรงโน้มถ่วง
   - ไม่ส่งเสียง
   - ไม่บันทึกเป็น Entity ของ chunk
   - ใช้การ teleport ไล่ตำแหน่ง จึงไม่พึ่ง AI ของ Vanilla Mob

3. การเรียกสัตว์หลังผู้เล่นเข้าเซิร์ฟเวอร์หน่วง 20 ticks
   เพื่อให้ข้อมูลผู้เล่นและระบบ Entity พร้อมก่อนสร้าง Pet

4. `/rankcmd` เป็นเมนูปุ่มสำหรับคำสั่งตามยศ
   - กดคำสั่งที่ต้องใช้ข้อมูล เช่น /tp /enchant /nick /size /time /kick /readmin
     แล้วจะเปิดฟอร์มกรอกข้อมูล
   - `/fly`, `/fix`, `/heal` กดใช้จาก UI ได้ทันที
   - `/pets` และ `/rankrename` เปิดเมนูของระบบโดยตรง
   - ตรวจสิทธิ์ซ้ำอีกครั้งก่อนทำคำสั่ง

5. ข้อมูลการจัดยศ/คำสั่ง/สัตว์เลี้ยงยังอ้างอิงจาก `resources/config.yml`

## การติดตั้ง

1. ปิด PocketMine-MP
2. แตก ZIP นี้เข้าโฟลเดอร์ `plugins`
3. ต้องมี SimpleMoney อยู่ด้วย เพราะ CuteRanks ระบุ `depend: SimpleMoney`
4. เปิดเซิร์ฟเวอร์ใหม่

โครงสร้างที่ถูกต้อง:

plugins/
└── CuteRanks/
    ├── plugin.yml
    ├── resources/
    │   └── config.yml
    └── src/
        └── CuteRanks/
            ├── Main.php
            ├── chat/
            ├── form/
            └── pet/

## คำสั่งหลัก

/rank
/rankcmd
/rankrename
/pets
/fly
/tp <ผู้เล่น>
/fix
/heal
/enchant <ชื่อมนตร์> [เลเวล]
/nick <ชื่อ|off>
/size <ขนาด>
/time <เวลา>
/kick <ผู้เล่น> [เหตุผล]
/readmin [ข้อความ]

## หมายเหตุ

ไฟล์นี้ตรวจ syntax ด้วย PHP 8.4 แล้วทุกไฟล์ผ่าน (`php -l`).
แต่การตรวจ `php -l` ไม่สามารถยืนยันการทำงานในเซิร์ฟเวอร์จริงได้ 100%
จึงควรทดสอบกับ PocketMine-MP 5.44.4 และ SimpleMoney เวอร์ชันที่เซิร์ฟเวอร์ใช้อยู่

เวอร์ชันแพ็ก: 2.0.1
