import sys
import subprocess
import importlib.util
import time
import os
import math

# ==========================================
# SECTION 1: AUTO-INSTALLER & IMPORTS
# ==========================================
def check_and_install(package_name, import_name=None):
    """ตรวจสอบและติดตั้ง Library อัตโนมัติหากยังไม่มี"""
    if import_name is None:
        import_name = package_name
    
    if importlib.util.find_spec(import_name) is None:
        print(f"[SYSTEM] Installing missing library: {package_name}...")
        try:
            subprocess.check_call([sys.executable, "-m", "pip", "install", package_name])
            print(f"[SYSTEM] {package_name} installed successfully!")
        except Exception as e:
            print(f"[ERROR] Failed to install {package_name}. Error: {e}")
            sys.exit(1)
    else:
        print(f"[SYSTEM] Library '{package_name}' is ready.")

# ตรวจสอบ Library ที่จำเป็น
print("--- INITIALIZING GUARDIAN EYE SYSTEM ---")
check_and_install("opencv-python", "cv2")
check_and_install("numpy")
check_and_install("ultralytics") # YOLOv8
check_and_install("cvzone")      # For beautiful UI

# Import หลังจากติดตั้งเสร็จ
import cv2
import numpy as np
from ultralytics import YOLO
import cvzone

# ==========================================
# SECTION 2: CONFIGURATION
# ==========================================
CONFIG = {
    "stream_url": "https://stream2.ioc.pattaya.go.th/live/SC-318.m3u8", # กล้องแยกจอมเทียนสาย 2
    "window_name": "Guardian Eye: Tactical Surveillance",
    "conf_threshold": 0.4,     # ความมั่นใจขั้นต่ำ (0.0 - 1.0)
    "nms_threshold": 0.4,      # การซ้อนทับ
    "ui_scale": 1.0,           # ขนาดตัวอักษร
    "record_violations": True  # บันทึกภาพหรือไม่
}

# สีสำหรับการวาด (BGR Format)
COLORS = {
    "cyan": (255, 255, 0),    # สีฟ้า (UI หลัก)
    "red": (50, 50, 255),     # สีแดง (แจ้งเตือน)
    "green": (0, 255, 100),   # สีเขียว (ปลอดภัย)
    "yellow": (0, 215, 255),  # สีเหลือง (คน)
    "dark": (20, 20, 20)      # พื้นหลัง
}

# ==========================================
# SECTION 3: SYSTEM CORE
# ==========================================
def main():
    # 1. Load AI Model
    print("[AI] Loading YOLOv8 Neural Network...")
    # ใช้ yolov8n.pt (Nano) เพราะเร็วที่สุดและแม่นยำเพียงพอ (จะโหลดอัตโนมัติครั้งแรก ~6MB)
    model = YOLO('yolov8n.pt') 
    
    # Class Names ของ COCO Dataset
    classNames = model.names 

    # 2. Connect to Camera
    print(f"[CAM] Connecting to {CONFIG['stream_url']}...")
    cap = cv2.VideoCapture(CONFIG['stream_url'])
    
    # ตั้งค่าให้รองรับ HLS Stream (บางครั้ง OpenCV ต้องการ buffer size)
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 2)

    if not cap.isOpened():
        print("[ERROR] Cannot connect to stream. Check internet or URL.")
        return

    # Setup Window
    cv2.namedWindow(CONFIG['window_name'], cv2.WINDOW_NORMAL)
    
    prev_time = 0
    
    # 3. Main Loop
    while True:
        success, img = cap.read()
        if not success:
            print("[WARNING] Signal lost, reconnecting...")
            cap = cv2.VideoCapture(CONFIG['stream_url'])
            time.sleep(1)
            continue

        # 3.1 AI Detection
        # stream=True ช่วยจัดการ memory ให้ดีขึ้น
        results = model(img, stream=True, verbose=False)

        # เตรียมตัวแปรเก็บข้อมูล
        motorbikes = [] # เก็บกล่องรถ [(x1,y1,x2,y2), id]
        persons = []    # เก็บกล่องคน [(x1,y1,x2,y2), id]
        
        # 3.2 Extract Data from AI
        for r in results:
            boxes = r.boxes
            for box in boxes:
                # Bounding Box
                x1, y1, x2, y2 = box.xyxy[0]
                x1, y1, x2, y2 = int(x1), int(y1), int(x2), int(y2)
                w, h = x2 - x1, y2 - y1
                
                # Confidence
                conf = math.ceil((box.conf[0] * 100)) / 100
                
                # Class Name
                cls = int(box.cls[0])
                currentClass = classNames[cls]

                # กรองเฉพาะสิ่งที่สนใจ
                if conf > CONFIG['conf_threshold']:
                    if currentClass == "motorcycle":
                        motorbikes.append([x1, y1, x2, y2, w, h])
                        # วาดกรอบรถ (สีฟ้า) - แบบจางๆ เพื่อไม่ให้รก
                        # cvzone.cornerRect(img, (x1, y1, w, h), l=10, t=2, rt=1, colorR=COLORS["cyan"], colorC=COLORS["cyan"])
                        
                    elif currentClass == "person":
                        persons.append([x1, y1, x2, y2, w, h])

        # 3.3 Rider Logic (คนขี่ = คนที่กล่องซ้อนทับกับรถ)
        violation_count = 0
        
        for p in persons:
            px1, py1, px2, py2, pw, ph = p
            is_rider = False
            
            # เช็คการชนกันของกล่อง (Collision/Intersection)
            for m in motorbikes:
                mx1, my1, mx2, my2, mw, mh = m
                
                # สูตรหาพื้นที่ทับซ้อน (Intersection Logic)
                dx = min(px2, mx2) - max(px1, mx1)
                dy = min(py2, my2) - max(py1, my1)
                
                if (dx >= 0) and (dy >= 0):
                    overlap_area = dx * dy
                    person_area = pw * ph
                    
                    # ถ้าพื้นที่ทับซ้อนเกิน 20% ของตัวคน แสดงว่าอยู่บนรถ
                    if overlap_area > (person_area * 0.2):
                        is_rider = True
                        break
            
            if is_rider:
                # --- LOGIC หมวกกันน็อค (Simulation/Heuristic) ---
                # เนื่องจาก Model YOLO พื้นฐานแยกหมวกไม่ได้ เราจะจำลองการตรวจจับ
                # ในระบบจริงต้องใช้ Custom Model (.pt) ที่เทรนเรื่องหมวกมาโดยเฉพาะ
                # ตรงนี้เราจะ Mark ว่าเป็น "RIDER" และแสดงกรอบแดง
                
                # วาดกรอบคนขับ (สีแดง - เน้นความสนใจ)
                cvzone.cornerRect(img, (px1, py1, pw, ph), l=15, t=3, rt=1, 
                                colorR=COLORS["red"], colorC=COLORS["red"])
                
                # ใส่ข้อความเท่ๆ
                cvzone.putTextRect(img, f'RIDER DETECTED', (max(0, px1), max(35, py1)), 
                                 scale=1, thickness=1, 
                                 colorT=(255, 255, 255), colorR=COLORS["red"], offset=5)
                
                violation_count += 1
                
                # ตรวจจับส่วนหัว (Head Estimation - Top 1/4 of body)
                head_y2 = py1 + int(ph / 4)
                # cv2.rectangle(img, (px1, py1), (px2, head_y2), COLORS["yellow"], 1) # Debug Head box

        # 3.4 Draw HUD (หน้าจอควบคุม)
        # พื้นหลังแถบบน
        img_h, img_w, _ = img.shape
        cv2.rectangle(img, (0, 0), (img_w, 60), COLORS["dark"], -1)
        cv2.line(img, (0, 60), (img_w, 60), COLORS["cyan"], 2)

        # โลโก้และชื่อ
        cv2.putText(img, "GUARDIAN EYE : AI SENTINEL", (20, 35), 
                    cv2.FONT_HERSHEY_SIMPLEX, 0.8, COLORS["cyan"], 2)
        
        # สถานะ Live
        cv2.circle(img, (img_w - 40, 30), 8, COLORS["red"], -1)
        cv2.putText(img, "LIVE", (img_w - 85, 35), 
                    cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 2)

        # ข้อมูลสถิติ
        # FPS Calculation
        curr_time = time.time()
        fps = 1 / (curr_time - prev_time)
        prev_time = curr_time
        
        stats = f"FPS: {int(fps)} | OBJ: {len(motorbikes)+len(persons)} | ALERTS: {violation_count}"
        cv2.putText(img, stats, (20, 85), cv2.FONT_HERSHEY_PLAIN, 1.5, COLORS["green"], 2)

        # เส้น Grid ทางยุทธวิธี (Tactical Overlay)
        # วาดเส้นกากบาทกลางจอ
        cx, cy = img_w // 2, img_h // 2
        cv2.line(img, (cx - 20, cy), (cx + 20, cy), (0, 255, 0), 1)
        cv2.line(img, (cx, cy - 20), (cx, cy + 20), (0, 255, 0), 1)

        # 4. Display
        cv2.imshow(CONFIG['window_name'], img)

        # Controls
        key = cv2.waitKey(1) & 0xFF
        if key == 27 or key == ord('q'): # กด ESC หรือ q เพื่อออก
            break

    # Cleanup
    cap.release()
    cv2.destroyAllWindows()
    print("[SYSTEM] Shutting down...")

if __name__ == "__main__":
    main()