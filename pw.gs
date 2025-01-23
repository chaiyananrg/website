function doGet(e) {
  if (e.parameter.id) {
    return handleQRCode(e.parameter.id);
  }

  return HtmlService.createHtmlOutputFromFile('index')
    .setTitle("🚗 ระบบลานจอดรถอัจฉริยะ")
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function createQRCode() {
  const sheet = SpreadsheetApp.openById('1D5mF3oFmXP0w7FrxvgSuA0vNG9dkkjrwaMHmvwOiTIs').getSheetByName('ParkingData');
  const id = generateRandomID();
  const scriptUrl = "https://script.google.com/macros/s/AKfycbzJbq34mP9ASkHElQaK-9Koejg55QeiEkZyoZIGFnw3BZgEXy1gbtzzE2jBGEfun3F2cA/exec";
  const qrLink = `${scriptUrl}?id=${id}`;

  // บันทึกข้อมูลเฉพาะ ID ใน Google Sheets
  sheet.appendRow([id, '', '', '', '', '', '', '']);

  return `https://api.qrserver.com/v1/create-qr-code/?data=${encodeURIComponent(qrLink)}&size=200x200`;
}

function generateRandomID() {
  return Math.random().toString(36).substr(2, 9);
}

function handleQRCode(id) {
  const sheet = SpreadsheetApp.openById('1D5mF3oFmXP0w7FrxvgSuA0vNG9dkkjrwaMHmvwOiTIs').getSheetByName('ParkingData');
  const rows = sheet.getDataRange().getValues();
  const now = new Date();

  for (let i = 1; i < rows.length; i++) {
    if (rows[i][0] === id) {
      if (!rows[i][1]) { // สแกนครั้งแรก (เข้า)
        const timeIn = formatTime(now);
        const dateIn = formatDateThai(now);

        sheet.getRange(i + 1, 2).setValue('🚗 เข้า');
        sheet.getRange(i + 1, 3).setValue(timeIn);
        sheet.getRange(i + 1, 4).setValue(dateIn);

        return HtmlService.createHtmlOutput(`
          <html>
            <head>
              <style>
                body { font-family: Arial, sans-serif; text-align: center; margin: 50px; background-color: #f8f9fa; }
                .container { background: white; padding: 20px; border-radius: 10px; box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1); max-width: 500px; margin: auto; }
                .header { font-size: 28px; color: #007bff; font-weight: bold; }
                .details { font-size: 18px; margin-top: 20px; line-height: 1.6; text-align: left; }
                .highlight { font-size: 22px; font-weight: bold; color: #28a745; }
                .footer { font-size: 16px; margin-top: 20px; color: #555; }
              </style>
            </head>
            <body>
              <div class="container">
                <div class="header">🚗 เข้าลานจอดรถสำเร็จ!</div>
                <div class="details">
                  <p>🔑 <b>รหัสผู้ใช้:</b> ${id}</p>
                  <p>📅 <b>วันเข้า:</b> ${dateIn}</p>
                  <p>🕒 <b>เวลาเข้า:</b> ${timeIn}</p>
                  <p>⏳ <b>สถานะ:</b> <span class="highlight">กำลังจอดรถ</span></p>
                </div>
                <div class="footer">🙏 ขอบพระคุณที่ใช้บริการ โอกาสหน้าเชิญใหม่นะครับ 😊</div>
              </div>
            </body>
          </html>
        `);
      } else {
        return handleExit(id, rows, i, now);
      }
    }
  }
  return HtmlService.createHtmlOutput("❌ ไม่พบข้อมูล QR Code!");
}

function handleExit(id, rows, i, now) {
  const sheet = SpreadsheetApp.openById('1D5mF3oFmXP0w7FrxvgSuA0vNG9dkkjrwaMHmvwOiTIs').getSheetByName('ParkingData');
  const timeIn = rows[i][2];
  const timeOut = formatTime(now);

  const duration = calculateDuration(timeIn, timeOut);
  const fee = duration.totalMinutes * 2;

  sheet.getRange(i + 1, 2).setValue('🏁 ออก');
  sheet.getRange(i + 1, 5).setValue(formatDateThai(now));
  sheet.getRange(i + 1, 6).setValue(timeOut);
  sheet.getRange(i + 1, 7).setValue(duration.text);
  sheet.getRange(i + 1, 8).setValue(fee);

  return HtmlService.createHtmlOutput(`
    <html>
      <head>
        <style>
          body { font-family: Arial, sans-serif; text-align: center; margin: 50px; background-color: #f8f9fa; }
          .container { background: white; padding: 20px; border-radius: 10px; box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1); max-width: 500px; margin: auto; }
          .header { font-size: 28px; color: #155724; font-weight: bold; }
          .details { font-size: 18px; margin-top: 20px; line-height: 1.6; text-align: left; }
          .highlight { font-size: 22px; font-weight: bold; color: #28a745; }
          .footer { font-size: 16px; margin-top: 20px; color: #555; }
        </style>
      </head>
      <body>
        <div class="container">
          <div class="header">🏁 ออกจากลานจอดสำเร็จ! 🚗</div>
          <div class="details">
            <p>🔑 <b>รหัสผู้ใช้:</b> ${id}</p>
            <p>📅 <b>วันออก:</b> ${formatDateThai(now)}</p>
            <p>🕒 <b>เวลาออก:</b> ${timeOut}</p>
            <p>⏳ <b>ระยะเวลาที่จอด:</b> <span class="highlight">${duration.text}</span></p>
            <p>💵 <b>ค่าใช้จ่ายทั้งหมด:</b> <span class="highlight">${fee} บาท</span></p>
          </div>
          <div class="footer">🙏 ขอบพระคุณที่ใช้บริการ โอกาสหน้าเชิญใหม่นะครับ 😊</div>
        </div>
      </body>
    </html>
  `);
}

function calculateDuration(timeIn, timeOut) {
  const [inHours, inMinutes, inSeconds] = timeIn.split(':').map(Number);
  const [outHours, outMinutes, outSeconds] = timeOut.split(':').map(Number);

  let diffHours = outHours - inHours;
  let diffMinutes = outMinutes - inMinutes;
  let diffSeconds = outSeconds - inSeconds;

  if (diffSeconds < 0) {
    diffSeconds += 60;
    diffMinutes -= 1;
  }
  if (diffMinutes < 0) {
    diffMinutes += 60;
    diffHours -= 1;
  }
  if (diffHours < 0) diffHours += 24;

  const totalMinutes = diffHours * 60 + diffMinutes;

  return {
    text: `${diffHours} ชั่วโมง ${diffMinutes} นาที ${diffSeconds} วินาที`,
    totalMinutes: totalMinutes + Math.floor(diffSeconds / 60),
  };
}

function formatDateThai(date) {
  const days = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
  const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  const day = date.getDate();
  const month = date.getMonth();
  const year = date.getFullYear() + 543;
  const weekday = days[date.getDay()];

  return `วัน${weekday} ที่ ${day} ${months[month]} พ.ศ. ${year}`;
}

function formatTime(date) {
  return `${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}:${date.getSeconds().toString().padStart(2, '0')}`;
}
