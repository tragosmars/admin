<?php
/**
 * Created by PhpStorm.
 * User: apple GeneratorCommand
 * Date: 2019/10/10
 * Time: 11:09:48
 */

namespace App\Storage\Database;

use App\Storage\Database;
use App\Generator\Storage\Database\TransactionOrderTrait;

class TransactionOrder extends Database
{
    use TransactionOrderTrait;
    protected $table = 'transaction_orders';
    //TODO please complete $fields
    protected $fields = [
        'id' => 'id',
        'order_id' => 'orderId',
        'uid' => 'uid',
        'transactions_id' => 'transactionsID',
        'num' => 'num',
        'pay_type' => 'payType',
        'pay_list' => 'payList',
        'pay_num' => 'payNum',
        'term_of_validity' => 'termOfValidity',
        'status' => 'status',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
    ];

    public function index($order_id)
    {
        $w = array(
            'transactions_id' => $order_id
        );
        return $this->with('user')->with(['complaint'=>function($query){
            $query->where('type',1);
        }])->where($w)->orderBy('created_at','desc')->paginate(config('sys.page_num'));
    }

    public function getPayListAttribute($value)
    {
        $pay = config('sys.pay');
        $list = json_decode($value, true);
        $new = array_unique($list);
        $str = '';
        foreach ($new as $v){
            $str .= $pay[$v].',';
        }
        $str = trim($str,',');
        return $str;
    }
    public function user()
    {
        return $this->belongsTo('App\Storage\Database\User','uid','id');
    }
    public function sellUser()
    {
        return $this->belongsTo('App\Storage\Database\User','sell_uid','id');
    }

    public function complaint()
    {
        return $this->hasOne('App\Storage\Database\Complaint','order','order_id');

    }



}