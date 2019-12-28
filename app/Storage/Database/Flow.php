<?php
/**
 * Created by PhpStorm.
 * User: apple GeneratorCommand
 * Date: 2019/10/09
 * Time: 17:26:25
 */

namespace App\Storage\Database;

use App\Storage\Database;
use App\Generator\Storage\Database\FlowTrait;
use App\Events\NoticeUser;
use App\Events\SendTalk;
use App\Repositories\AccountRepository;
use App\Repositories\UserInfoRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Flow extends Database
{
    use FlowTrait;
    protected $table = 'flows';
    //TODO please complete $fields
    protected $fields = [
        'id' => 'id',
        'uid' => 'uid',
        'flow_type' => 'flowType',
        'source' => 'source',
        'num' => 'num',
        'before_num' => 'beforeNum',
        'after_num' => 'afterNum',
        'symbol' => 'symbol',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
    ];

    protected  const LIST_SELL = 1;//挂单出售
    protected  const LIST_BUY = 2;//挂单购买
    protected  const LIST_BUY_REWARD = 3;//挂单购买奖励
    protected  const LIST_REWARD = 4;//挂单购买分成奖励

    protected const RED_PACKET_NO_PAY_MONEY = 5;//红包任务商户过期未支付人民币奖励
    protected const RED_PACKET_CANCEL = 6;//红包商户取消
    protected const RED_PACKET_SUUCESS = 7;//红包交易成功

    protected const SYMBOL_ADD = 1;
    protected const SYMBOL_SUB = 2;

    protected $fillable = [
        'uid',
        'flow_type',
        'source',
        'num',
        'before_num',
        'after_num',
        'symbol',
    ];



    /*
    * 红包任务商户过期未付款 && 商户取消
    * $task 订单对象
    * $type 取消类型 1 超时取消  2 主动取消
    * */
    public function redPacketNoPayMoney(Task $task, $type = 1 )
    {
        $user = $task->user;//用户信息
        $user_acc = $user->account;//用户账户
        $group = $user->getGroup;
        $Acc_obj = resolve(AccountRepository::class);
        $master_acc = $Acc_obj->getDataInfo($group->master);//群主账户
        $curreny_num = config('share.red_packet.pay_money_expire');

        try{
            DB::beginTransaction();
            $user_before = bcadd($user_acc->flow, $user_acc->frozen,2);
            $user_after = bcadd($user_before, $curreny_num,2);
            $user_flow = bcadd($user_acc->flow,$task->num,2);//取消冻结
            $user_real_flow = bcadd($user_flow,$curreny_num,2);
            $user_frozen = bcsub($user_acc->frozen, $task->num,2);
            if ($type == 1){
                $flow_type = self::RED_PACKET_NO_PAY_MONEY;
            }else{
                $flow_type = self::RED_PACKET_CANCEL;
            }
            $data = array(
                'uid' => $task->uid,
                'flow_type' => $flow_type,
                'source' => $task->order,
                'num' => $curreny_num,
                'before_num' => $user_before,
                'after_num' => $user_after,
                'symbol' => self::SYMBOL_ADD,
            );
            $user_acc->flow = $user_real_flow;
            $user_acc->frozen = $user_frozen;
            $user_acc->reward = bcadd($user_acc->reward, $curreny_num,2);
            $ret = $user_acc->save();
            if($ret){
                $result = $this->create($data);
                Log::channel('flow')->debug(json_encode($data));
                if ($result){
                    //处理群主
                    $master_befor = bcadd($master_acc->flow,$master_acc->frozen,2);
                    $master_after = bcsub($master_befor, $curreny_num,2);
                    //需要考虑群主币是否足够。默认足够
                    $master_acc->flow = bcsub($master_acc->flow, $curreny_num,2);
                    $master_ret = $master_acc->save();
                    if ($master_ret){
                        $master_data = array(
                            'uid' => $group->master,
                            'flow_type' => $flow_type,
                            'source' => $task->order,
                            'num' => $curreny_num,
                            'before_num' => $master_befor,
                            'after_num' => $master_after,
                            'symbol' => self::SYMBOL_SUB,
                        );
                        $master_result = $this->create($master_data);
                        Log::channel('flow')->debug(json_encode($master_data));
                        if ($master_result){
                            //修改状态
                            $taskOBJ = new Task();
                            if ($type == 1){
                                $task_result = $taskOBJ->setPayMoneyCancel($task,$curreny_num);
                                $talk_type = 'task_pay_money_overtime';//推送类型
                            }else{
                                $task_result = $taskOBJ->setCancel($task,$curreny_num);
                                $talk_type = 'task_cancel';
                            }
                            if ($task_result){
                                $content = array(
                                    'type'=>'user_nocite_1',
                                    'content' => '订单'.$task->order.' 任务已取消！，获得' . $curreny_num.'个币奖励',    //通知内容
                                    'time' => time(),    //通知时间 -时间戳
                                );
                                event(new NoticeUser($content,'ORDER',$task->uid));
                                DB::commit();
                                return true;
                            }

                        }
                    }
                }
            }
            DB::rollBack();
            return false;
        }catch (\Exception $exception){
            DB::rollBack();
            return false;
        }
    }


    //红包用户卖家付币
    public function setRedPacketSuccess(Task $task)
    {
        DB::beginTransaction();
        try {
            $user_acc = $task->getSellAccount;//用户信息
            $red_acc = $task->getBuyAccount;//红包账户账户
            //卖家账户处理
            $user_before = bcadd($user_acc->flow, $user_acc->frozen, 2);
            $user_after = bcsub($user_before, $task->num, 2);
            $user_acc->frozen = bcsub($user_acc->frozen, $task->num, 2);
            $user_ret = $user_acc->save();
            if ($user_ret) {
                $user_flow = array(
                    'uid' => $task->uid,
                    'flow_type' => self::RED_PACKET_SUUCESS,
                    'source' => $task->order,
                    'num' => $task->num,
                    'before_num' => $user_before,
                    'after_num' => $user_after,
                    'symbol' => self::SYMBOL_SUB,
                );
                $user_flow_ret = $this->create($user_flow);
                Log::channel('flow')->debug(json_encode($user_flow));//
                if ($user_flow_ret) {
                    //用户处理成功，处理商户
                    $red_before = bcadd($red_acc->flow, $red_acc->frozen, 2);
                    $red_after = bcadd($red_before, $task->num, 2);
                    $red_acc->flow = bcadd($red_acc->flow, $task->num, 2);
                    $red_ret = $red_acc->save();
                    if ($red_ret) {
                        //流水
                        $ret_flow = array(
                            'uid' => $task->send_uid,
                            'flow_type' => self::RED_PACKET_SUUCESS,
                            'source' => $task->order,
                            'num' => $task->num,
                            'before_num' => $red_before,
                            'after_num' => $red_after,
                            'symbol' => self::SYMBOL_ADD,
                        );
                        $red_flow_ret = $this->create($ret_flow);
                        Log::channel('flow')->debug(json_encode($ret_flow));
                        if ($red_flow_ret) {
                            //修改订单状态
                            $taskOBJ = new Task();
                            $ret = $taskOBJ->setPayCurrency($task);
                            if ($ret) {
                                DB::commit();
                                $content = array(
                                    'type'=>'user_nocite_1',
                                    'content' => '订单'.$task->order.'交易成功！',    //通知内容
                                    'time' => time(),    //通知时间 -时间戳
                                );
                                event(new NoticeUser($content,'ORDER',$task->uid));
                                return true;
                            }
                        }
                    }

                }
            }
            DB::rollBack();
            return false;
        } catch (\Exception $exception) {
            DB::rollBack();
            return false;
        }
    }

}