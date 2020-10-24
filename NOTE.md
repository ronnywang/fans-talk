==== websocket ====
狀態
----
* status
  * answering
    * 正準備要回答問題，此時不會有任何配對
    * 回答完問題後會變成 pairing
    * 指令
        * S=>C: 第一次登入提供線上人數和題目資訊
          {"type":"welcome","people_count":123,"questions":{...}}
        * C=>S: 回答問題等待配對
          {"type":"answer","answers":{}}
  * pairing
    * 正準備要配對
    * 有配對到的話可以進到 requesting 狀態
    * 指令
        * C=>S: 回答問題等待配對
          {"type":"answer","answers":{}}
  * requesting
    * 得到雙方同意中
    * 雙方都同意的話會進入 chating
    * 有一方拒絕就進到 pairing
        * S=>C: 配對到了，詢問是否要接受 (accepted 表示對方接受了沒，可能會收到兩次 macthed)
          {"type":"matched","answers":{},"accepted":false}
        * C=>S: 同意配對
          {"type":"accept"}
        * C=>S: 拒絕配對
          {"type":"reject"}
        * S=>C: 對方取消了配對
          {"type":"canceled"}
        * S=>C: 開始聊天，收到後進入 chating 狀態
          {"type":"start"}
  * chating
    * 開始聊天中
    * 當有人送出 end 時會結束聊天回到 pairing 狀態
        * C=>S: 說話
          {"type":"talk","message":"xxx"}
        * S=>C: 有人說話
          {"type":"talk","message":"xxx"}
        * C=>S: 結束對話
          {"type":"end"}
        * S=>C: 對方取消了配對，收到後進入 pairing 狀態
          {"type":"canceled"}



====以下廢止====
* local storage (永久保存資料)
  * answers
    * JSON object, key 是題號, value 是 -2 ~ +2 或 null ，配對用的
* session storage
  * rejected
    * JSON array, 已經拒絕的使用者列表，避免重覆對拒絕的使用者做邀請
* property
  * user-status
    * answering: 回答問題中，此時不做配對，一進來狀態是這個
    * talking: 聊天中，此時不能被打擾
    * asking: 詢問中，等雙方都同意就進入 talking
    * pairing: 配對中，此時可被配對
  * answers
    * JSON format, key 是題號, value 是 -2 ~ +2 或 null ，配對用的
* action
  * invite-ask
    * 前端自動配對，對符合條件送出 invite 後自己要變成 asking status 避免被重複邀請
  * reject-ask
    * 前端自動拒絕配對，解除 invite 狀態（可能是已經剛好被其他人邀請了）
  * accept-ask
    * 前端自動接受配對，此時會跳出提示詢問當事人是否接受聊天
  * accept-invite
    * 當事人接受配對，雙方都 accept-invite 就開聊
  * reject-invite
    * 當事人拒絕配對，一方 rejcet 就直接解除回到另外 invite-ask 狀態
* TODO
  * 允許旁觀模式？
  * 結束後允許留存對話記錄?
