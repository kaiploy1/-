# CuteRanks v2.0.2 FIX

แก้ Crash จาก CuteRanks v2.0.1 FIXED

## อาการ
Console แจ้ง:
`Call to undefined method CuteRanks\\form\\CustomForm::addButton()`

เกิดจาก `Main.php` เรียก `$form->addButton(...)` แต่ `CustomForm` เป็น Custom Form และคลาสนี้ไม่มีเมธอด `addButton()`

## วิธีแก้
ลบบรรทัด `addButton()` ออกจาก `showRankCommandInput()`
เพราะ Minecraft Bedrock Custom Form ใช้ช่อง input/toggle/dropdown และการส่งข้อมูลของตัวฟอร์มเอง ไม่ต้องเพิ่มปุ่มแบบ Simple Form

## ตรวจสอบ
- PHP syntax: ผ่านทุกไฟล์
- ไม่เพิ่ม dependency ภายนอก
- โครงสร้าง plugin ยังคงเดิม
