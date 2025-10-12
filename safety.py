import subprocess
import sys
import time

# --- ฟังก์ชันเช็คและติดตั้ง library ---
def install(package):
    subprocess.check_call([sys.executable, "-m", "pip", "install", package])

try:
    import cv2
except ModuleNotFoundError:
    print("ติดตั้ง opencv-python...")
    install("opencv-python")
    import cv2

try:
    import mediapipe as mp
except ModuleNotFoundError:
    print("ติดตั้ง mediapipe...")
    install("mediapipe")
    import mediapipe as mp

# --- ตั้งค่า Mediapipe Pose ---
mp_pose = mp.solutions.pose
pose = mp_pose.Pose(min_detection_confidence=0.5, min_tracking_confidence=0.5)
mp_draw = mp.solutions.drawing_utils

# --- ตั้งค่ากล้อง ---
cap = cv2.VideoCapture(0)  # 0 คือกล้องหลัก
fall_detected = False
fall_start_time = None
FALL_THRESHOLD = 5  # วินาทีที่นอนราบก่อนแจ้งเตือน

def is_person_fallen(landmarks):
    """
    ตรวจสอบว่าร่างกายนอนราบ
    ใช้ตำแหน่งไหล่และสะโพก
    """
    left_shoulder = landmarks[mp_pose.PoseLandmark.LEFT_SHOULDER.value]
    right_shoulder = landmarks[mp_pose.PoseLandmark.RIGHT_SHOULDER.value]
    left_hip = landmarks[mp_pose.PoseLandmark.LEFT_HIP.value]
    right_hip = landmarks[mp_pose.PoseLandmark.RIGHT_HIP.value]

    shoulder_y = (left_shoulder.y + right_shoulder.y) / 2
    hip_y = (left_hip.y + right_hip.y) / 2

    # ถ้าไหล่และสะโพกอยู่แนวนอนมาก → นอนราบ
    if abs(shoulder_y - hip_y) < 0.1:
        return True
    return False

# --- เริ่มตรวจจับ fall ---
while True:
    ret, frame = cap.read()
    if not ret:
        break

    frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
    results = pose.process(frame_rgb)

    if results.pose_landmarks:
        mp_draw.draw_landmarks(frame, results.pose_landmarks, mp_pose.POSE_CONNECTIONS)

        if is_person_fallen(results.pose_landmarks.landmark):
            if fall_start_time is None:
                fall_start_time = time.time()
            elif time.time() - fall_start_time > FALL_THRESHOLD:
                if not fall_detected:
                    fall_detected = True
                    print("⚠️ ALERT: Person has fallen!")  # แจ้งเตือน
        else:
            fall_start_time = None
            fall_detected = False

    cv2.imshow("Fall Detection", frame)
    if cv2.waitKey(1) & 0xFF == ord('q'):
        break

cap.release()
cv2.destroyAllWindows()